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
    return $this->resolveDefaultTokens($this->defaults, $now);
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

      $fieldType = $fieldDef['type'];
      // Reference fields can validate nested item definitions.
      if (
        $fieldType === 'entity_reference' ||
        $fieldType === 'entity_reference_revisions'
      ) {
        $this->validateReferenceField($definition, $fieldDef, $path, $result, $fieldManager, $bundleInfo);
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
  private function validateReferenceField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
    object $fieldManager,
    object $bundleInfo,
  ): void {
    $storageType = $definition->getFieldStorageDefinition()->getType();
    $expectedStorageType = $fieldDef['type'];
    $targetType = $definition->getFieldStorageDefinition()->getSetting('target_type');

    // The template type must match the Drupal field storage type.
    if ($storageType !== $expectedStorageType) {
      $result->addError("Field '$path' is a '$storageType' field, not '$expectedStorageType'.");
      return;
    }

    // Respect target bundle restrictions from the field instance.
    $allowedBundles = $this->getAllowedBundles($definition);

    foreach ($fieldDef['items'] ?? [] as $i => $item) {
      $itemPath = "$path.items[$i]";
      $itemEntityType = $item['entity_type'] ?? '';
      $itemBundle = $item['bundle'] ?? '';

      // Each item must declare the entity type it describes.
      if ($itemEntityType === '') {
        $result->addError("Item $itemPath: missing entity_type.");
        continue;
      }

      // Items must target the same entity type as the field.
      if ($itemEntityType !== $targetType) {
        $result->addError("Item $itemPath: entity_type '$itemEntityType' does not match field target type '$targetType'.");
        continue;
      }

      // Each item must declare the bundle it references.
      if ($itemBundle === '') {
        $result->addError("Item $itemPath: missing bundle.");
        continue;
      }

      // The referenced bundle must exist.
      if (!isset($bundleInfo->getBundleInfo($itemEntityType)[$itemBundle])) {
        $result->addError("Item $itemPath: bundle '$itemBundle' does not exist on entity type '$itemEntityType'.");
        continue;
      }

      // The field may restrict which bundles are allowed.
      if ($allowedBundles !== NULL && !in_array($itemBundle, $allowedBundles, TRUE)) {
        $allowed = implode(', ', $allowedBundles);
        $result->addError("Item $itemPath: bundle '$itemBundle' is not allowed in field '$path' (allowed: $allowed).");
        continue;
      }

      // Nested fields are validated against the referenced bundle.
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

    foreach ($defaults as $fieldName => $default) {
      if (!isset($definitions[$fieldName])) {
        $result->addError("Default field '$fieldName' does not exist on content type '$bundle'.", 'defaults');
        continue;
      }

      if (!is_array($default)) {
        $result->addError("Default field '$fieldName' must be a mapping with type and default_value keys.", 'defaults');
        continue;
      }

      $expectedType = $definitions[$fieldName]->getFieldStorageDefinition()->getType();
      $configuredType = $default['type'] ?? NULL;
      if (!is_string($configuredType) || $configuredType === '') {
        $result->addError("Default field '$fieldName' is missing type.", 'defaults');
      }
      elseif ($configuredType !== $expectedType) {
        $result->addError("Default field '$fieldName' is a '$expectedType' field, not '$configuredType'.", 'defaults');
      }

      if (!array_key_exists('default_value', $default)) {
        $result->addError("Default field '$fieldName' is missing default_value.", 'defaults');
      }
      elseif (!is_array($default['default_value'])) {
        $result->addError("Default field '$fieldName' default_value must be a sequence.", 'defaults');
      }
    }
  }

  /**
   * Recursively replaces supported token strings in default values.
   *
   * @param mixed $value
   *   The default value or nested value.
   * @param int $now
   *   The Unix timestamp to use for __NOW__.
   *
   * @return mixed
   *   The value with tokens resolved.
   */
  private function resolveDefaultTokens(mixed $value, int $now): mixed {
    if ($value === '__NOW__') {
      return $now;
    }
    if (!is_array($value)) {
      return $value;
    }
    return array_map(
      fn(mixed $item): mixed => $this->resolveDefaultTokens($item, $now),
      $value,
    );
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
