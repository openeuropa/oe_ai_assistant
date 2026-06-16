<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateListBuilder;
use Drupal\oe_ai_assistant\Exception\TemplateValidationException;
use Drupal\oe_ai_assistant\Form\AiDraftingTemplateForm;
use Drupal\oe_ai_assistant\TemplateValidationResult;

/**
 * Defines the AI Drafting Template config entity type.
 */
#[ConfigEntityType(
  id: 'ai_drafting_template',
  label: new TranslatableMarkup('AI Drafting Template'),
  label_collection: new TranslatableMarkup('AI Drafting Templates'),
  label_singular: new TranslatableMarkup('AI drafting template'),
  label_plural: new TranslatableMarkup('AI drafting templates'),
  config_prefix: 'ai_drafting_template',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => AiDraftingTemplateListBuilder::class,
    'form' => [
      'add' => AiDraftingTemplateForm::class,
      'edit' => AiDraftingTemplateForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/ai-editorial/templates',
    'add-form' => '/admin/config/ai-editorial/templates/add',
    'edit-form' => '/admin/config/ai-editorial/templates/{ai_drafting_template}',
    'delete-form' => '/admin/config/ai-editorial/templates/{ai_drafting_template}/delete',
  ],
  admin_permission: 'administer ai_drafting_template',
  label_count: [
    'singular' => '@count AI drafting template',
    'plural' => '@count AI drafting templates',
  ],
  config_export: [
    'id',
    'label',
    'status',
    'description',
    'content_type',
    'fields',
    'defaults',
  ],
)]
final class AiDraftingTemplate extends ConfigEntityBase implements AiDraftingTemplateInterface {

  /**
   * The ID.
   */
  protected string $id = '';

  /**
   * The label.
   */
  protected string $label = '';

  /**
   * The description.
   */
  protected string $description = '';

  /**
   * The target node bundle machine name.
   */
  protected string $content_type = '';

  /**
   * The ordered field definitions map.
   *
   * @var array<string, mixed>
   */
  protected array $fields = [];

  /**
   * The default values map.
   *
   * @var array<string, mixed>
   */
  protected array $defaults = [];

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function getContentType(): string {
    return $this->content_type;
  }

  /**
   * {@inheritdoc}
   */
  public function getFields(): array {
    return $this->fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaults(): array {
    return $this->defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveDefaults(): array {
    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = \Drupal::service(TimeInterface::class);
    $now = $time->getRequestTime();
    return array_map(
      static fn($value) => $value === '__NOW__' ? $now : $value,
      $this->defaults,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(): TemplateValidationResult {
    $result = new TemplateValidationResult();

    $contentType = $this->content_type;
    if ($contentType === '') {
      return $result;
    }

    $bundleInfo = \Drupal::service('entity_type.bundle.info');
    $fieldManager = \Drupal::service('entity_field.manager');

    $bundles = $bundleInfo->getBundleInfo('node');
    if (!isset($bundles[$contentType])) {
      $result->addError("Content type '$contentType' does not exist.", 'content_type');
      return $result;
    }

    $this->validateFieldsAgainstEntityType('node', $contentType, $this->fields, '', $result, $fieldManager, $bundleInfo);
    $this->validateDefaultsAgainstEntityType('node', $contentType, $this->defaults, $result, $fieldManager);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $result = $this->validate();

    if (!$result->isValid()) {
      throw new TemplateValidationException($this->id(), $result);
    }
  }

  /**
   * Validates fields against the definitions of an entity type and bundle.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $fields
   *   The field definitions to validate.
   * @param string $pathPrefix
   *   The path prefix for nested validation errors.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param object $fieldManager
   *   The entity field manager service.
   * @param object $bundleInfo
   *   The entity type bundle info service.
   */
  private function validateFieldsAgainstEntityType(
    string $entityType,
    string $bundle,
    array $fields,
    string $pathPrefix,
    TemplateValidationResult $result,
    object $fieldManager,
    object $bundleInfo,
  ): void {
    $definitions = $fieldManager->getFieldDefinitions($entityType, $bundle);

    foreach ($fields as $fieldName => $fieldDef) {
      $path = $pathPrefix !== '' ? "$pathPrefix > $fieldName" : $fieldName;

      if (!isset($definitions[$fieldName])) {
        $entityLabel = $entityType === 'node'
          ? "content type '$bundle'"
          : "$entityType '$bundle'";
        $result->addError("Field '$fieldName' does not exist on $entityLabel.");
        continue;
      }

      $definition = $definitions[$fieldName];

      if (!array_key_exists('type', $fieldDef)) {
        continue;
      }

      if ($fieldDef['type'] === 'paragraphs') {
        $this->validateParagraphsField($definition, $fieldDef, $path, $result, $fieldManager, $bundleInfo);
      }
      elseif ($fieldDef['type'] === 'entity_reference') {
        $this->validateEntityReferenceField($definition, $fieldDef, $path, $result, $fieldManager, $bundleInfo);
      }
    }
  }

  /**
   * Validates a paragraphs field and its item definitions.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param array<string, mixed> $fieldDef
   *   The template field definition.
   * @param string $path
   *   The field path for validation errors.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param object $fieldManager
   *   The entity field manager service.
   * @param object $bundleInfo
   *   The entity type bundle info service.
   */
  private function validateParagraphsField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
    object $fieldManager,
    object $bundleInfo,
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

      if (!isset($bundleInfo->getBundleInfo('paragraph')[$paragraphType])) {
        $result->addError("Paragraph type '$paragraphType' does not exist (referenced at $itemPath).");
        continue;
      }

      if ($allowedBundles !== NULL && !in_array($paragraphType, $allowedBundles, TRUE)) {
        $allowed = implode(', ', $allowedBundles);
        $result->addError("Paragraph type '$paragraphType' is not allowed in field '$path' (allowed: $allowed).");
        continue;
      }

      if (!empty($item['fields'])) {
        $this->validateFieldsAgainstEntityType('paragraph', $paragraphType, $item['fields'], "$itemPath > fields", $result, $fieldManager, $bundleInfo);
      }
    }
  }

  /**
   * Validates an entity reference field and its item definitions.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   * @param array<string, mixed> $fieldDef
   *   The template field definition.
   * @param string $path
   *   The field path for validation errors.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param object $fieldManager
   *   The entity field manager service.
   * @param object $bundleInfo
   *   The entity type bundle info service.
   */
  private function validateEntityReferenceField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
    object $fieldManager,
    object $bundleInfo,
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

      if (!isset($bundleInfo->getBundleInfo($itemEntityType)[$itemBundle])) {
        $result->addError("Item $itemPath: bundle '$itemBundle' does not exist on entity type '$itemEntityType'.");
        continue;
      }

      if ($allowedBundles !== NULL && !in_array($itemBundle, $allowedBundles, TRUE)) {
        $allowed = implode(', ', $allowedBundles);
        $result->addError("Item $itemPath: bundle '$itemBundle' is not allowed in field '$path' (allowed: $allowed).");
        continue;
      }

      if (!empty($item['fields'])) {
        $this->validateFieldsAgainstEntityType($itemEntityType, $itemBundle, $item['fields'], "$itemPath > fields", $result, $fieldManager, $bundleInfo);
      }
    }
  }

  /**
   * Validates default field names against an entity type and bundle.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $defaults
   *   The default values to validate.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param object $fieldManager
   *   The entity field manager service.
   */
  private function validateDefaultsAgainstEntityType(
    string $entityType,
    string $bundle,
    array $defaults,
    TemplateValidationResult $result,
    object $fieldManager,
  ): void {
    if (empty($defaults)) {
      return;
    }

    $definitions = $fieldManager->getFieldDefinitions($entityType, $bundle);

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
   *   The field definition.
   *
   * @return string[]|null
   *   The allowed bundle IDs, or NULL when unrestricted.
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
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $storage = $this->entityTypeManager()->getStorage('node_type');
    $content_type = $storage->load($this->content_type);
    $name = $content_type->getConfigDependencyName();

    $this->addDependency('config', $name);

    return $this;
  }

}
