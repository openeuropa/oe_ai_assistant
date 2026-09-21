<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates, merges template defaults, and builds an unsaved draft node.
 */
class DraftAssembler implements DraftAssemblerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    private readonly DraftEntityBuilder $draftEntityBuilder,
    private readonly TemplateDefaultsResolverInterface $defaultsResolver,
    private readonly RequestStack $requestStack,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assemble(string $bundle, array $fields, ?string $templateId, ?ContentEntityInterface $existingNode = NULL): ContentEntityInterface {
    if ($existingNode === NULL) {
      if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
        throw new ActionException('invalid_bundle',
          sprintf('Content type "%s" does not exist.', $bundle), 400);
      }

      if (!$this->currentUser->hasPermission("create $bundle content")) {
        throw new ActionException(
          'forbidden',
          sprintf('You do not have permission to create %s content.', $bundle),
          403,
        );
      }
    }
    elseif (!$existingNode->access('update', $this->currentUser)) {
      throw new ActionException(
        'forbidden',
        'You do not have permission to update this node.',
        403,
      );
    }

    $template = NULL;
    if ($templateId !== NULL && $templateId !== '') {
      try {
        $template = $this->schemaProvider->resolveTemplate('node', $bundle, $templateId);
      }
      catch (\InvalidArgumentException $e) {
        $this->logger->warning('Drafting template "@template" could not be resolved for node bundle "@bundle": @message', [
          '@template' => $templateId,
          '@bundle' => $bundle,
          '@message' => $e->getMessage(),
        ]);
        throw new ActionException('invalid_request', $e->getMessage(), 400);
      }
    }

    $mergedFields = $fields;
    if ($template !== NULL) {
      // Defaults resolution and the merge share one catch: both read the
      // template's configured values, so a failure in either is a template
      // problem, not a problem with what the model drafted.
      try {
        $defaultsByBundle = $this->resolveDefaultsByBundle($template);
        $applied = [];
        $mergedFields = $this->applyDefaults($fields, 'node', $bundle, $defaultsByBundle, $applied);
      }
      catch (\Throwable $e) {
        $this->logger->error('Defaults of drafting template "@template" could not be applied to node bundle "@bundle": @exception', [
          '@template' => (string) $template->id(),
          '@bundle' => $bundle,
          '@exception' => (string) $e,
        ]);
        throw new ActionException(
          'defaults_failed',
          'The drafting template defaults could not be applied. See the system log for details.',
          500,
        );
      }
      $this->logAppliedDefaults((string) $template->id(), $applied);
    }

    // Park the merged payload on the request so the stages that run after
    // this one can dump it when they fail. It never leaves the request.
    $this->requestStack->getCurrentRequest()?->attributes
      ->set(DraftAssemblerInterface::PAYLOAD_REQUEST_ATTRIBUTE, $mergedFields);

    try {
      $built = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $mergedFields);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build draft entity of node bundle "@bundle": @exception Payload: @payload', [
        '@bundle' => $bundle,
        '@exception' => (string) $e,
        '@payload' => $this->encodePayload($mergedFields),
      ]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    if ($existingNode === NULL) {
      $this->logViolations($built);
      return $built;
    }

    // Transplant only the merged fields' values onto the existing node, the
    // same field-by-field idiom core's EntityResource::patch() uses for
    // updates, so fields outside this draft are left untouched.
    foreach (array_keys($mergedFields) as $fieldName) {
      $existingNode->set($fieldName, $built->get($fieldName)->getValue());
    }
    $this->logViolations($existingNode);
    return $existingNode;
  }

  /**
   * Resolves all template defaults, keyed by entity type and bundle.
   *
   * @return array
   *   Resolved value lists keyed by entity type ID, bundle and field name, in
   *   the shape DraftEntityBuilder::fromLlmFields() takes.
   */
  private function resolveDefaultsByBundle(AiDraftingTemplateInterface $template): array {
    $resolved = [];
    foreach ($template->getDefaultsByBundle() as $entityTypeId => $bundles) {
      foreach ($bundles as $bundle => $defaults) {
        $resolved[$entityTypeId][$bundle] = array_map(
          static fn (array $default) => $default['default_value'],
          $this->defaultsResolver->resolve($defaults, $entityTypeId, $bundle),
        );
      }
    }
    return $resolved;
  }

  /**
   * Merges defaults into the fields and into every inline entity below them.
   *
   * A default wins over a drafted value for the same field, so editors keep
   * control over the values a template pins. Inline entities are matched by
   * bundle, so a default declared once applies to every item of that bundle
   * at any depth.
   *
   * @param array $fields
   *   A fields map in the serialization shape of the given bundle.
   * @param string $entityTypeId
   *   The entity type the fields belong to.
   * @param string $bundle
   *   The bundle the fields belong to.
   * @param array $defaultsByBundle
   *   Resolved value lists keyed by entity type ID, bundle and field name.
   * @param array $applied
   *   Tally of what this walk merged, collected for the diagnostic log and
   *   keyed "entity_type:bundle". Each entry holds the merged field names
   *   and how many entities of that bundle received them.
   *
   * @return array
   *   The fields map with defaults merged in.
   */
  private function applyDefaults(array $fields, string $entityTypeId, string $bundle, array $defaultsByBundle, array &$applied): array {
    if ($defaultsByBundle === []) {
      return $fields;
    }
    $bundleDefaults = $defaultsByBundle[$entityTypeId][$bundle] ?? [];
    if ($bundleDefaults !== []) {
      $key = "$entityTypeId:$bundle";
      $applied[$key]['fields'] = array_keys($bundleDefaults);
      $applied[$key]['count'] = ($applied[$key]['count'] ?? 0) + 1;
    }
    $fields = $bundleDefaults + $fields;

    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($fields as $fieldName => &$items) {
      $targetType = isset($definitions[$fieldName]) ? $definitions[$fieldName]->getSetting('target_type') : NULL;
      if ($targetType === NULL || !is_array($items)) {
        continue;
      }
      $bundleKey = $this->entityTypeManager->getDefinition($targetType)->getKey('bundle');
      if (!$bundleKey) {
        continue;
      }
      foreach ($items as &$item) {
        $itemBundle = is_array($item) ? ($item[$bundleKey][0]['target_id'] ?? NULL) : NULL;
        if ($itemBundle === NULL) {
          continue;
        }
        $item = $this->applyDefaults($item, $targetType, $itemBundle, $defaultsByBundle, $applied);
      }
    }
    return $fields;
  }

  /**
   * Logs which defaults the merge injected, and into how many entities.
   *
   * @param string $templateId
   *   The drafting template the defaults came from.
   * @param array $applied
   *   The tally collected by applyDefaults().
   */
  private function logAppliedDefaults(string $templateId, array $applied): void {
    if ($applied === []) {
      $this->logger->debug('Drafting template "@template" declares no defaults.', [
        '@template' => $templateId,
      ]);
      return;
    }

    $summary = [];
    foreach ($applied as $key => $entry) {
      $summary[] = sprintf(
        '%s x%d (%s)',
        $key,
        $entry['count'],
        implode(', ', $entry['fields']),
      );
    }
    $this->logger->debug('Drafting template "@template" applied defaults to @summary', [
      '@template' => $templateId,
      '@summary' => implode('; ', $summary),
    ]);
  }

  /**
   * Logs the constraint violations of a built draft tree.
   *
   * Diagnostic only: the entity is returned and saved or previewed whatever
   * this finds. Core does not cascade validation into unsaved inline
   * children, so every referenced child is validated explicitly.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to validate.
   * @param string $path
   *   The nesting path of the entity within the draft tree, empty at the
   *   root.
   */
  private function logViolations(ContentEntityInterface $entity, string $path = ''): void {
    foreach ($entity->validate() as $violation) {
      $this->logger->warning('Draft @type:@bundle is invalid at @path: @message', [
        '@type' => $entity->getEntityTypeId(),
        '@bundle' => $entity->bundle(),
        '@path' => $path === ''
          ? $violation->getPropertyPath()
          : $path . '.' . $violation->getPropertyPath(),
        '@message' => strip_tags((string) $violation->getMessage()),
      ]);
    }

    foreach ($entity->getFields() as $fieldName => $itemList) {
      if ($itemList->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
        continue;
      }
      foreach ($itemList as $delta => $item) {
        $child = $item->entity;
        if (!$child instanceof ContentEntityInterface) {
          continue;
        }
        $childPath = sprintf('%s%s[%d]', $path === '' ? '' : $path . '.', $fieldName, $delta);
        $this->logViolations($child, $childPath);
      }
    }
  }

  /**
   * Encodes a draft payload for the log, never throwing on bad input.
   *
   * @param array $payload
   *   The merged fields map.
   *
   * @return string
   *   The payload as JSON, or a short notice when it cannot be encoded.
   */
  private function encodePayload(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '<payload could not be encoded>' : $json;
  }

}
