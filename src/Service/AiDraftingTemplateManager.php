<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\TemplateValidationResult;

/**
 * Loads templates and validates them against structural and Drupal field rules.
 */
final class AiDraftingTemplateManager implements AiDraftingTemplateManagerInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTemplatesForContentType(string $content_type): array {
    return $this->entityTypeManager
      ->getStorage('ai_drafting_template')
      ->loadByProperties(['content_type' => $content_type]);
  }

  /**
   * {@inheritdoc}
   */
  public function loadTemplate(string $templateId): AiDraftingTemplateInterface {
    $template = $this->entityTypeManager
      ->getStorage('ai_drafting_template')
      ->load($templateId);

    if (!$template instanceof AiDraftingTemplateInterface) {
      throw new \InvalidArgumentException("AI drafting template '$templateId' not found.");
    }

    return $template;
  }

  /**
   * {@inheritdoc}
   */
  public function validateTemplate(AiDraftingTemplateInterface $template): TemplateValidationResult {
    $result = new TemplateValidationResult();
    $fields = $template->getFields();
    $defaults = $template->getDefaults();

    // Validate against actual Drupal field definitions.
    $contentType = $template->getContentType();
    if ($contentType !== '') {
      $this->validateContentTypeExists($contentType, $result);
      if ($result->isValid()) {
        $this->validateFieldsAgainstEntityType('node', $contentType, $fields, '', $result);
        $this->validateDefaultsAgainstEntityType('node', $contentType, $defaults, $result);
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveDefaults(array $defaults): array {
    $now = $this->time->getRequestTime();
    return array_map(
      static fn($value) => $value === '__NOW__' ? $now : $value,
      $defaults,
    );
  }

  /**
   * Checks that the content type exists as a node bundle.
   *
   * Level 2: Drupal field definition validation
   *
   * @param string $contentType
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   */
  private function validateContentTypeExists(string $contentType, TemplateValidationResult $result): void {
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('node');
    if (!isset($bundles[$contentType])) {
      $result->addError("Content type '$contentType' does not exist.", 'content_type');
    }
  }

  /**
   * Recursively validates fields against the field definitions of an entity type + bundle.
   *
   * Level 2: Drupal field definition validation
   *
   * @param string $entityType
   * @param string $bundle
   * @param array<string, mixed> $fields
   * @param string $pathPrefix
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   */
  private function validateFieldsAgainstEntityType(
    string $entityType,
    string $bundle,
    array $fields,
    string $pathPrefix,
    TemplateValidationResult $result,
  ): void {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityType, $bundle);

    foreach ($fields as $fieldName => $fieldDef) {
      $path = $pathPrefix !== '' ? "$pathPrefix > $fieldName" : $fieldName;

      if (!isset($definitions[$fieldName])) {
        $entityLabel = $entityType === 'node' ? "content type '$bundle'" : "$entityType '$bundle'";
        $result->addError("Field '$fieldName' does not exist on $entityLabel.");
        continue;
      }

      $definition = $definitions[$fieldName];

      if (!array_key_exists('type', $fieldDef)) {
        continue;
      }

      if ($fieldDef['type'] === 'paragraphs') {
        $this->validateParagraphsField($definition, $fieldDef, $path, $result);
      }
      elseif ($fieldDef['type'] === 'entity_reference') {
        $this->validateEntityReferenceField($definition, $fieldDef, $path, $result);
      }
    }
  }

  /**
   * Validates that a paragraphs field targets the correct storage type and
   * that every item references an allowed paragraph bundle.
   *
   * Level 2: Drupal field definition validation
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   * @param array<string, mixed> $fieldDef
   * @param string $path
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   */
  private function validateParagraphsField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
  ): void {
    $storageType = $definition->getFieldStorageDefinition()->getType();
    $targetType = $definition->getFieldStorageDefinition()->getSetting('target_type');

    if ($storageType !== 'entity_reference_revisions' || $targetType !== 'paragraph') {
      $result->addError("Field '$path' is not a paragraph reference field (entity_reference_revisions targeting paragraph).");
      return;
    }

    $allowedBundles = $this->getAllowedBundles($definition);

    foreach ($fieldDef['items'] ?? [] as $i => $item) {
      $paragraphType = $item['paragraph_type'] ?? '';
      $itemPath = "$path.items[$i]";

      if (!$this->bundleExists('paragraph', $paragraphType)) {
        $result->addError("Paragraph type '$paragraphType' does not exist (referenced at $itemPath).");
        continue;
      }

      if ($allowedBundles !== NULL && !in_array($paragraphType, $allowedBundles, TRUE)) {
        $allowed = implode(', ', $allowedBundles);
        $result->addError("Paragraph type '$paragraphType' is not allowed in field '$path' (allowed: $allowed).");
        continue;
      }

      if (!empty($item['fields'])) {
        $this->validateFieldsAgainstEntityType('paragraph', $paragraphType, $item['fields'], "$itemPath > fields", $result);
      }
    }
  }

  /**
   * Validates that an entity_reference field targets the correct entity type
   * and that every item references an allowed bundle.
   *
   * Level 2: Drupal field definition validation
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   * @param array<string, mixed> $fieldDef
   * @param string $path
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   */
  private function validateEntityReferenceField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
  ): void {
    $storageType = $definition->getFieldStorageDefinition()->getType();
    $targetType = $definition->getFieldStorageDefinition()->getSetting('target_type');

    if ($storageType !== 'entity_reference') {
      $result->addError("Field '$path' is not an entity_reference field.");
      return;
    }

    $allowedBundles = $this->getAllowedBundles($definition);

    foreach ($fieldDef['items'] ?? [] as $i => $item) {
      $itemEntityType = $item['entity_type'] ?? '';
      $itemBundle = $item['bundle'] ?? '';
      $itemPath = "$path.items[$i]";

      if ($itemEntityType !== $targetType) {
        $result->addError("Item $itemPath: entity_type '$itemEntityType' does not match field target type '$targetType'.");
        continue;
      }

      if (!$this->bundleExists($itemEntityType, $itemBundle)) {
        $result->addError("Item $itemPath: bundle '$itemBundle' does not exist on entity type '$itemEntityType'.");
        continue;
      }

      if ($allowedBundles !== NULL && !in_array($itemBundle, $allowedBundles, TRUE)) {
        $allowed = implode(', ', $allowedBundles);
        $result->addError("Item $itemPath: bundle '$itemBundle' is not allowed in field '$path' (allowed: $allowed).");
        continue;
      }

      if (!empty($item['fields'])) {
        $this->validateFieldsAgainstEntityType($itemEntityType, $itemBundle, $item['fields'], "$itemPath > fields", $result);
      }
    }
  }

  /**
   * Validates that all field names in the defaults map exist on the entity type.
   *
   * Level 2: Drupal field definition validation
   *
   * @param string $entityType
   * @param string $bundle
   * @param array<string, mixed> $defaults
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   */
  private function validateDefaultsAgainstEntityType(
    string $entityType,
    string $bundle,
    array $defaults,
    TemplateValidationResult $result,
  ): void {
    if (empty($defaults)) {
      return;
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions($entityType, $bundle);

    foreach (array_keys($defaults) as $fieldName) {
      if (!isset($definitions[$fieldName])) {
        $result->addError("Default field '$fieldName' does not exist on content type '$bundle'.", 'defaults');
      }
    }
  }

  /**
   * Returns the allowed target bundles for a field, or NULL if unrestricted.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *
   * @return string[]|null
   */
  private function getAllowedBundles(FieldDefinitionInterface $definition): ?array {
    $handlerSettings = $definition->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? NULL;
    if ($targetBundles === NULL || $targetBundles === []) {
      return NULL;
    }
    return array_values($targetBundles);
  }

  /**
   * Checks whether a bundle exists for the given entity type.
   *
   * @param string $entityType
   * @param string $bundle
   */
  private function bundleExists(string $entityType, string $bundle): bool {
    return isset($this->entityTypeBundleInfo->getBundleInfo($entityType)[$bundle]);
  }

}
