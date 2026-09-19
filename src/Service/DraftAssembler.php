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
        throw new ActionException('invalid_request', $e->getMessage(), 400);
      }
    }

    $mergedFields = $fields;
    try {
      if ($template !== NULL) {
        $mergedFields = $this->applyDefaults(
          $fields,
          'node',
          $bundle,
          $this->resolveDefaultsByBundle($template),
        );
      }
      $built = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $mergedFields);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to build draft entity: @e', ['@e' => (string) $e]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    if ($existingNode === NULL) {
      return $built;
    }

    // Transplant only the merged fields' values onto the existing node, the
    // same field-by-field idiom core's EntityResource::patch() uses for
    // updates, so fields outside this draft are left untouched.
    foreach (array_keys($mergedFields) as $fieldName) {
      $existingNode->set($fieldName, $built->get($fieldName)->getValue());
    }
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
   *
   * @return array
   *   The fields map with defaults merged in.
   */
  private function applyDefaults(array $fields, string $entityTypeId, string $bundle, array $defaultsByBundle): array {
    if ($defaultsByBundle === []) {
      return $fields;
    }
    $fields = ($defaultsByBundle[$entityTypeId][$bundle] ?? []) + $fields;

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
        $item = $this->applyDefaults($item, $targetType, $itemBundle, $defaultsByBundle);
      }
    }
    return $fields;
  }

}
