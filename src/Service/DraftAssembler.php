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

    $mergedFields = $fields;
    $template = NULL;
    if ($templateId !== NULL && $templateId !== '') {
      try {
        $template = $this->schemaProvider->resolveTemplate('node', $bundle, $templateId);
      }
      catch (\InvalidArgumentException $e) {
        throw new ActionException('invalid_request', $e->getMessage(), 400);
      }
      if ($template !== NULL) {
        // resolveDefaults() mirrors the raw config shape defined by
        // oe_ai_assistant.ai_drafting_template_default in
        // config/schema/oe_ai_assistant.schema.yml: each field's value list
        // is wrapped in a 'default_value' key. Unwrap it here so the merged
        // map is a flat field-name => value-list map, the shape
        // fromLlmFields() expects.
        $resolvedDefaults = array_map(
          static fn (array $default) => $default['default_value'],
          $template->resolveDefaults(),
        );
        // Template defaults win on collision, so editors keep control over
        // the values a template pins regardless of what the LLM produced.
        $mergedFields = $resolvedDefaults + $fields;
      }
    }

    try {
      if ($template !== NULL) {
        $itemDefaults = $this->collectItemDefaults($template->getFields(), $template);
        $mergedFields = $this->applyItemDefaults($mergedFields, 'node', $bundle, $itemDefaults);
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
    foreach ($mergedFields as $fieldName => $submitted) {
      $values = $built->get($fieldName)->getValue();
      $existingItems = $existingNode->get($fieldName);
      if ($existingItems->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinition('format')) {
        // A format the payload did not supply was resolved for a new entity;
        // the one already stored on the item takes precedence over it.
        foreach (array_keys($values) as $delta) {
          $existingFormat = $existingItems->get($delta)?->format;
          if ($existingFormat && empty($submitted[$delta]['format'])) {
            $values[$delta]['format'] = $existingFormat;
          }
        }
      }
      $existingNode->set($fieldName, $values);
    }
    return $existingNode;
  }

  /**
   * Collects item defaults declared anywhere in the template, keyed by bundle.
   *
   * @return array<string, array<string, mixed>>
   *   Field name => value list maps keyed by "entity_type:bundle".
   */
  private function collectItemDefaults(array $templateFields, AiDraftingTemplateInterface $template): array {
    $defaults = [];
    foreach ($templateFields as $fieldConfig) {
      foreach ($fieldConfig['items'] ?? [] as $item) {
        $key = $item['entity_type'] . ':' . $item['bundle'];
        if (!empty($item['defaults'])) {
          $defaults[$key] = ($defaults[$key] ?? []) + array_map(
            static fn (array $default) => $default['default_value'],
            $template->resolveItemDefaults($item['defaults'], $item['entity_type'], $item['bundle']),
          );
        }
        $defaults += $this->collectItemDefaults($item['fields'] ?? [], $template);
      }
    }
    return $defaults;
  }

  /**
   * Applies item defaults to every inline entity of a defaulted bundle.
   *
   * Walks the LLM output through the entity reference fields of each bundle,
   * so a default declared once for a bundle lands on all its items at any
   * depth. Defaults win on collision, as node-level defaults do.
   */
  private function applyItemDefaults(array $fields, string $entityTypeId, string $bundle, array $itemDefaults): array {
    if ($itemDefaults === []) {
      return $fields;
    }
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($fields as $fieldName => &$items) {
      $targetType = isset($definitions[$fieldName]) ? $definitions[$fieldName]->getSetting('target_type') : NULL;
      if ($targetType === NULL || !is_array($items)) {
        continue;
      }
      $bundleKey = $this->entityTypeManager->getDefinition($targetType)->getKey('bundle');
      foreach ($items as &$item) {
        $itemBundle = $bundleKey && is_array($item) ? ($item[$bundleKey][0]['target_id'] ?? NULL) : NULL;
        if ($itemBundle === NULL) {
          continue;
        }
        $item = ($itemDefaults["$targetType:$itemBundle"] ?? []) + $item;
        $item = $this->applyItemDefaults($item, $targetType, $itemBundle, $itemDefaults);
      }
    }
    return $fields;
  }

}
