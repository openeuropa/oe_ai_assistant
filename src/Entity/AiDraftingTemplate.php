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
    return $this->resolveDefaultTokens($this->defaults, $this->getRequestTime());
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

    if (!$this->bundleExists('node', $contentType)) {
      $result->addError("Content type '$contentType' does not exist.", 'content_type');
      return $result;
    }

    $this->validateFields('node', $contentType, $this->fields, '', $result, $this->defaults);

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
   * @param array<string, mixed> $defaults
   *   Default values that satisfy required fields for this entity type.
   */
  private function validateFields(
    string $entityType,
    string $bundle,
    array $fields,
    string $pathPrefix,
    TemplateValidationResult $result,
    array $defaults = [],
  ): void {
    $definitions = $this->getFieldDefinitions($entityType, $bundle);

    $this->validateDefaults($entityType, $bundle, $defaults, $pathPrefix, $result, $definitions);

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

      if (!is_array($fieldDef)) {
        continue;
      }

      if (array_key_exists('default_value', $fieldDef)) {
        $this->validateDefaultValue($entityType, $bundle, $fieldName, $fieldDef, $result, 'fields');
      }

      if (!array_key_exists('type', $fieldDef)) {
        continue;
      }

      $fieldType = $fieldDef['type'];
      // Reference fields can validate nested item definitions.
      if (
        $fieldType === 'entity_reference' ||
        $fieldType === 'entity_reference_revisions'
      ) {
        $this->validateReferenceField($definition, $fieldDef, $path, $result);
      }
    }

    $this->validateRequiredFields($entityType, $bundle, $fields, $defaults, $pathPrefix, $result, $definitions);
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
   */
  private function validateReferenceField(
    FieldDefinitionInterface $definition,
    array $fieldDef,
    string $path,
    TemplateValidationResult $result,
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
      if (!$this->bundleExists($itemEntityType, $itemBundle)) {
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
        $this->validateFields($itemEntityType, $itemBundle, $item['fields'], "$itemPath > fields", $result);
        continue;
      }

      $this->validateFields($itemEntityType, $itemBundle, [], "$itemPath > fields", $result);
    }
  }

  /**
   * Validates default values against field definitions and constraints.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $defaults
   *   The default values to validate.
   * @param string $pathPrefix
   *   The path prefix for nested validation errors.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $definitions
   *   The Drupal field definitions for the entity type and bundle.
   */
  private function validateDefaults(
    string $entityType,
    string $bundle,
    array $defaults,
    string $pathPrefix,
    TemplateValidationResult $result,
    array $definitions,
  ): void {
    foreach ($defaults as $fieldName => $default) {
      $fieldPath = $pathPrefix !== '' ? "$pathPrefix > $fieldName" : $fieldName;

      if (!isset($definitions[$fieldName])) {
        $entityLabel = $entityType === 'node'
          ? "content type '$bundle'"
          : "$entityType '$bundle'";
        $result->addError("Default field '$fieldPath' does not exist on $entityLabel.", 'defaults');
        continue;
      }

      $this->validateDefaultValue($entityType, $bundle, $fieldPath, $default, $result, 'defaults');
    }
  }

  /**
   * Validates that required, authorable fields are covered by the template.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $fields
   *   The field definitions to validate.
   * @param array<string, mixed> $defaults
   *   Default values that satisfy required fields for this entity type.
   * @param string $pathPrefix
   *   The path prefix for nested validation errors.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $definitions
   *   The Drupal field definitions for the entity type and bundle.
   */
  private function validateRequiredFields(
    string $entityType,
    string $bundle,
    array $fields,
    array $defaults,
    string $pathPrefix,
    TemplateValidationResult $result,
    array $definitions,
  ): void {
    foreach ($definitions as $fieldName => $definition) {
      if (
        !$definition->isRequired() ||
        $definition->isComputed() ||
        $definition->isReadOnly() ||
        !$definition->isDisplayConfigurable('form') ||
        array_key_exists($fieldName, $fields) ||
        array_key_exists($fieldName, $defaults)
      ) {
        continue;
      }

      $fieldPath = $pathPrefix !== '' ? "$pathPrefix > $fieldName" : $fieldName;
      $entityLabel = $entityType === 'node'
        ? "content type '$bundle'"
        : "$entityType '$bundle'";
      $result->addError("Required field '$fieldPath' is missing from template fields or defaults on $entityLabel.");
    }
  }

  /**
   * Returns whether an entity bundle exists.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   */
  private function bundleExists(string $entityType, string $bundle): bool {
    $bundleInfo = \Drupal::service('entity_type.bundle.info');
    return isset($bundleInfo->getBundleInfo($entityType)[$bundle]);
  }

  /**
   * Returns field definitions for an entity type and bundle.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The field definitions.
   */
  private function getFieldDefinitions(string $entityType, string $bundle): array {
    $fieldManager = \Drupal::service('entity_field.manager');
    return $fieldManager->getFieldDefinitions($entityType, $bundle);
  }

  /**
   * Validates one default field definition with Drupal field constraints.
   *
   * @param string $entityType
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param string $fieldName
   *   The field name.
   * @param mixed $default
   *   The default definition.
   * @param \Drupal\oe_ai_assistant\TemplateValidationResult $result
   *   The validation result to add errors to.
   * @param string $category
   *   The validation category to assign errors to.
   */
  private function validateDefaultValue(
    string $entityType,
    string $bundle,
    string $fieldName,
    mixed $default,
    TemplateValidationResult $result,
    string $category,
  ): void {
    if (!is_array($default)) {
      $result->addError("Default field '$fieldName' must be a mapping with a default_value key.", $category);
      return;
    }
    if (!array_key_exists('default_value', $default)) {
      $result->addError("Default field '$fieldName' is missing default_value.", $category);
      return;
    }
    if (!is_array($default['default_value']) || !array_is_list($default['default_value'])) {
      $result->addError("Default field '$fieldName' default_value must be a sequence.", $category);
      return;
    }

    try {
      $entityTypeManager = \Drupal::service('entity_type.manager');
      $entityTypeDefinition = $entityTypeManager->getDefinition($entityType);
      $bundleKey = $entityTypeDefinition->getKey('bundle');
      $values = $bundleKey ? [$bundleKey => $bundle] : [];
      /** @var \Drupal\Core\Entity\FieldableEntityInterface $entity */
      $entity = $entityTypeManager->getStorage($entityType)->create($values);
      $entity->set($fieldName, $this->resolveDefaultTokens($default['default_value'], $this->getRequestTime()));
      $field = $entity->get($fieldName);
    }
    catch (\Throwable $e) {
      $result->addError("Default field '$fieldName' default_value could not be applied: {$e->getMessage()}", $category);
      return;
    }

    foreach ($field->validate() as $violation) {
      $result->addError("Default field '$fieldName' default_value is invalid: {$violation->getMessage()}", $category);
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
   * Returns the current request time.
   */
  private function getRequestTime(): int {
    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = \Drupal::service(TimeInterface::class);
    return $time->getRequestTime();
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
