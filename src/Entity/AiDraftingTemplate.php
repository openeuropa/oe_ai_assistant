<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateListBuilder;
use Drupal\oe_ai_assistant\Exception\TemplateValidationException;
use Drupal\oe_ai_assistant\Form\AiDraftingTemplateForm;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

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
    $time = \Drupal::service(TimeInterface::class);
    $resolved = $this->resolveDefaultTokens($this->defaults, $time->getRequestTime());

    return $this->resolveDefaultsFor($resolved, 'node', $this->content_type);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveItemDefaults(array $rawDefaults, string $entityTypeId, string $bundle): array {
    $time = \Drupal::service(TimeInterface::class);
    $resolved = $this->resolveDefaultTokens($rawDefaults, $time->getRequestTime());

    return $this->resolveDefaultsFor($resolved, $entityTypeId, $bundle);
  }

  /**
   * Resolves target_uuid entries to target_id across a defaults map.
   *
   * @param array<string, mixed> $defaults
   *   A defaults map, token-resolved, shaped {field_name: {default_value:
   *   [...]}}.
   * @param string $entityTypeId
   *   The entity type ID that owns these fields.
   * @param string $bundle
   *   The bundle that owns these fields.
   *
   * @return array<string, mixed>
   *   The defaults map with target_uuid resolved to target_id.
   */
  private function resolveDefaultsFor(array $defaults, string $entityTypeId, string $bundle): array {
    $entityFieldManager = \Drupal::service(EntityFieldManagerInterface::class);
    $fieldDefinitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);

    foreach ($defaults as $fieldName => &$default) {
      if (
        !isset($fieldDefinitions[$fieldName]) ||
        !isset($default['default_value']) ||
        !is_array($default['default_value'])
      ) {
        continue;
      }

      $default['default_value'] = $this->resolveEntityReferenceDefaultValue(
        $default['default_value'],
        $fieldDefinitions[$fieldName],
        $entityTypeId,
        $bundle,
      );
    }

    return $defaults;
  }

  /**
   * Resolves target_uuid entries to target_id via core's own mechanism.
   *
   * Mirrors FieldDefaultValueConstraintValidator's identical helper: reuses
   * core's field-config-default resolution path
   * ($itemClass::processDefaultValue()) rather than reimplementing UUID
   * lookup. Duplicated deliberately rather than shared, per design decision.
   *
   * @param array $rawValue
   *   The raw default_value sequence.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The real field definition the default applies to.
   * @param string $entityTypeId
   *   The entity type ID that owns the field.
   * @param string $bundle
   *   The bundle that owns the field.
   *
   * @return array
   *   The default_value sequence with target_uuid resolved to target_id.
   *
   * @throws \RuntimeException
   *   If a target_uuid does not resolve to an existing entity.
   */
  private function resolveEntityReferenceDefaultValue(array $rawValue, FieldDefinitionInterface $fieldDefinition, string $entityTypeId, string $bundle): array {
    $hasUuidReference = FALSE;
    foreach ($rawValue as $item) {
      if (is_array($item) && array_key_exists('target_uuid', $item)) {
        $hasUuidReference = TRUE;
        break;
      }
    }
    if (!$hasUuidReference) {
      return $rawValue;
    }

    $entityTypeManager = \Drupal::service(EntityTypeManagerInterface::class);
    $bundleKey = $entityTypeManager->getDefinition($entityTypeId)->getKey('bundle');
    $scratch = $bundleKey
      ? $entityTypeManager->getStorage($entityTypeId)->create([$bundleKey => $bundle])
      : $entityTypeManager->getStorage($entityTypeId)->create();

    $fieldItemListClass = $fieldDefinition->getClass();
    $resolved = $fieldItemListClass::processDefaultValue($rawValue, $scratch, $fieldDefinition);

    if (count($resolved) < count($rawValue)) {
      throw new \RuntimeException(sprintf(
        "One or more target_uuid values for field '%s' do not reference an existing entity.",
        $fieldDefinition->getName(),
      ));
    }

    return $resolved;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(): ConstraintViolationListInterface {
    $violations = $this->getTypedData()->validate();

    $this->validateRequiredFields(
      $violations,
      'node',
      $this->content_type,
      $this->fields,
      $this->defaults,
    );

    return $violations;
  }

  /**
   * Validates that required fields are covered by fields or defaults.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $violations
   *   The validation violation list.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $fields
   *   The template field definitions.
   * @param array<string, mixed> $defaults
   *   The template default definitions.
   * @param string $path_prefix
   *   The nested property path prefix.
   */
  private function validateRequiredFields(
    ConstraintViolationListInterface $violations,
    string $entity_type_id,
    string $bundle,
    array $fields,
    array $defaults,
    string $path_prefix = '',
  ): void {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);

    $field_definitions = $entity_field_manager
      ->getFieldDefinitions($entity_type_id, $bundle);

    $defined_field_names = array_unique([
      ...array_keys($fields),
      ...array_keys($defaults),
    ]);

    foreach ($field_definitions as $field_name => $field_definition) {
      if (
        !$field_definition->isRequired() ||
        $field_definition->isComputed() ||
        $field_definition->isReadOnly() ||
        !$field_definition->isDisplayConfigurable('form') ||
        in_array($field_name, $defined_field_names, TRUE)
      ) {
        continue;
      }

      $field_path = $path_prefix === ''
        ? $field_name
        : "$path_prefix > fields > $field_name";

      $violations->add(new ConstraintViolation(
        sprintf(
          "Required field '%s' is missing from template fields or defaults on content type '%s'",
          $field_path,
          $bundle,
        ),
        "Required field '@field' is missing from template fields or defaults on content type '@bundle'",
        [
          '@field' => $field_path,
          '@bundle' => $bundle,
        ],
        $this,
        $path_prefix === '' ? "fields.$field_name" : "$path_prefix.fields.$field_name",
        NULL,
      ));
    }

    foreach ($fields as $field_name => $field_config) {
      if (
        empty($field_config['items']) ||
        !is_array($field_config['items'])
      ) {
        continue;
      }

      foreach ($field_config['items'] as $delta => $item) {
        if (!is_array($item)) {
          continue;
        }

        $target_entity_type_id = $item['entity_type'] ?? NULL;
        $target_bundle = $item['bundle'] ?? NULL;

        if (!$target_entity_type_id || !$target_bundle) {
          continue;
        }

        $this->validateRequiredFields(
          $violations,
          $target_entity_type_id,
          $target_bundle,
          $item['fields'] ?? [],
          $item['defaults'] ?? [],
          $path_prefix === ''
            ? "$field_name.items[$delta]"
            : "$path_prefix > fields > $field_name.items[$delta]",
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $result = $this->validate();

    if (count($result) > 0) {
      throw new TemplateValidationException($this->id(), $result);
    }

    parent::preSave($storage);
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
