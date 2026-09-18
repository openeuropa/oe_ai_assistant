<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
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
        $mergedFields = $this->mergeItemDefaults($template->getFields(), $mergedFields, $template);
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
   * Merges each template item's defaults into its matching LLM output item.
   *
   * Walks each field's template item declarations against the LLM's actual
   * output items for that field, matching each template item to the first
   * not-yet-matched LLM item sharing its bundle (read via the bundle
   * discriminator, the same shape InlineEntityHydrator reads) — not by array
   * position. The LLM is not guaranteed, and in practice does not reliably,
   * emit items in the order the template declares them; matching by bundle
   * instead of index means a defaulted item's value still lands on the
   * right item wherever the LLM placed it. A template item with no matching
   * LLM item is left unmerged, not an error — required-field coverage is
   * enforced at template-validation time, not here. Recurses into each
   * matched item's own nested items.
   *
   * @param array<string, mixed> $templateFields
   *   The template's field definitions
   *   (AiDraftingTemplateInterface::getFields() shape, or an item's own
   *   'fields' shape when called recursively).
   * @param array<string, mixed> $llmFields
   *   The merged LLM/defaults fields map to merge item defaults into.
   * @param \Drupal\oe_ai_assistant\AiDraftingTemplateInterface $template
   *   The template, for resolveItemDefaults() calls.
   *
   * @return array<string, mixed>
   *   The fields map with item-level defaults merged in.
   */
  private function mergeItemDefaults(array $templateFields, array $llmFields, AiDraftingTemplateInterface $template): array {
    foreach ($templateFields as $fieldName => $fieldConfig) {
      if (
        empty($fieldConfig['items']) ||
        !is_array($fieldConfig['items'])
      ) {
        continue;
      }

      $llmItems = isset($llmFields[$fieldName]) && is_array($llmFields[$fieldName])
        ? $llmFields[$fieldName]
        : [];

      // A provider may omit the bundle discriminator from an inline item,
      // even though it is required by the generated schema. Infer it only
      // when this field has exactly one defaulted template item; guessing
      // among multiple defaulted bundles could assign the wrong paragraph
      // type.
      $defaultedBundles = [];
      foreach ($fieldConfig['items'] as $templateItem) {
        if (
          is_array($templateItem)
          && !empty($templateItem['defaults'])
          && !empty($templateItem['entity_type'])
          && !empty($templateItem['bundle'])
        ) {
          $defaultedBundles[$templateItem['entity_type'] . ':' . $templateItem['bundle']] = [
            $templateItem['entity_type'],
            $templateItem['bundle'],
          ];
        }
      }
      if (count($defaultedBundles) === 1) {
        [$defaultedEntityTypeId, $defaultedBundle] = reset($defaultedBundles);
        $defaultedBundleKey = $this->entityTypeManager
          ->getDefinition($defaultedEntityTypeId)
          ->getKey('bundle');
        foreach ($llmItems as &$llmItem) {
          if (
            is_array($llmItem)
            && !isset($llmItem[$defaultedBundleKey][0]['target_id'])
          ) {
            $llmItem[$defaultedBundleKey] = [['target_id' => $defaultedBundle]];
          }
        }
        unset($llmItem);
      }

      $usedIndexes = [];

      foreach ($fieldConfig['items'] as $templateItem) {
        if (!is_array($templateItem)) {
          continue;
        }

        $itemEntityTypeId = $templateItem['entity_type'] ?? NULL;
        $itemBundle = $templateItem['bundle'] ?? NULL;
        if (!$itemEntityTypeId || !$itemBundle) {
          continue;
        }

        $bundleKey = $this->entityTypeManager->getDefinition($itemEntityTypeId)->getKey('bundle');

        $matchedIndex = NULL;
        foreach ($llmItems as $index => $llmItem) {
          if (isset($usedIndexes[$index]) || !is_array($llmItem)) {
            continue;
          }
          if (($llmItem[$bundleKey][0]['target_id'] ?? NULL) === $itemBundle) {
            $matchedIndex = $index;
            break;
          }
        }
        $itemDefaults = [];
        if (!empty($templateItem['defaults']) && is_array($templateItem['defaults'])) {
          $resolvedItemDefaults = array_map(
            static fn (array $default) => $default['default_value'],
            $template->resolveItemDefaults($templateItem['defaults'], $itemEntityTypeId, $itemBundle),
          );
          $itemDefaults = $resolvedItemDefaults;
        }

        if ($matchedIndex === NULL) {
          if ($itemDefaults === []) {
            continue;
          }
          $llmItem = [
            $bundleKey => [['target_id' => $itemBundle]],
          ];
        }
        else {
          $usedIndexes[$matchedIndex] = TRUE;
          $llmItem = $llmItems[$matchedIndex];
        }

        // Template defaults win on collision, including for an item emitted
        // by the LLM.
        $llmItem = $itemDefaults + $llmItem;

        if (!empty($templateItem['fields']) && is_array($templateItem['fields'])) {
          $llmItem = $this->mergeItemDefaults($templateItem['fields'], $llmItem, $template);
        }

        if ($matchedIndex === NULL) {
          $llmItems[] = $llmItem;
        }
        else {
          $llmItems[$matchedIndex] = $llmItem;
        }
      }

      $llmFields[$fieldName] = $llmItems;
    }

    return $llmFields;
  }

}
