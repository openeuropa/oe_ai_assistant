<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldConfigInterface;
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

    return $this->resolveDefaultTokens($this->defaults, $time->getRequestTime());
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
        $field_definition->getDefaultValueLiteral() !== [] ||
        $field_definition->getDefaultValueCallback() ||
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
   * {@inheritdoc}
   */
  public function isInstallable(): bool {
    $result = $this->validate();

    if (count($result) === 0) {
      return TRUE;
    }

    $this->logValidationFailure($result, 'skipped during config install');
    return FALSE;
  }

  /**
   * Logs template validation violations without aborting the caller.
   *
   * @param \Symfony\Component\Validator\ConstraintViolationListInterface $result
   *   The validation violations.
   * @param string $outcome
   *   Short description of what happened to the entity, for the log message.
   */
  private function logValidationFailure(ConstraintViolationListInterface $result, string $outcome): void {
    $errors = [];
    foreach ($result as $violation) {
      $errors[] = (string) $violation->getMessage();
    }
    \Drupal::logger('oe_ai_assistant')->error(
      "AI drafting template '%id' %outcome:\n- %errors",
      [
        '%id' => $this->id(),
        '%outcome' => $outcome,
        '%errors' => implode("\n- ", $errors),
      ],
    );
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

    $this->addFieldDependencies('node', $this->content_type, $this->fields, $this->defaults);

    return $this;
  }

  /**
   * Recursively adds config dependencies for referenced fields and bundles.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   * @param array<string, mixed> $fields
   *   The template field definitions.
   * @param array<string, mixed> $defaults
   *   The template default definitions.
   */
  private function addFieldDependencies(
    string $entity_type_id,
    string $bundle,
    array $fields,
    array $defaults,
  ): void {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    $field_definitions = $entity_field_manager->getFieldDefinitions($entity_type_id, $bundle);

    $defined_field_names = array_unique([
      ...array_keys($fields),
      ...array_keys($defaults),
    ]);

    foreach ($defined_field_names as $field_name) {
      $field_definition = $field_definitions[$field_name] ?? NULL;
      if ($field_definition instanceof FieldConfigInterface) {
        $this->addDependency('config', $field_definition->getConfigDependencyName());
      }
    }

    foreach ($fields as $field_config) {
      if (empty($field_config['items']) || !is_array($field_config['items'])) {
        continue;
      }

      foreach ($field_config['items'] as $item) {
        if (!is_array($item)) {
          continue;
        }

        $target_entity_type_id = $item['entity_type'] ?? NULL;
        $target_bundle = $item['bundle'] ?? NULL;

        if (!$target_entity_type_id || !$target_bundle) {
          continue;
        }

        $bundle_entity_type_id = $this->entityTypeManager()
          ->getDefinition($target_entity_type_id)
          ->getBundleEntityType();

        if ($bundle_entity_type_id) {
          $bundle_entity = $this->entityTypeManager()
            ->getStorage($bundle_entity_type_id)
            ->load($target_bundle);
          if ($bundle_entity) {
            $this->addDependency('config', $bundle_entity->getConfigDependencyName());
          }
        }

        $this->addFieldDependencies(
          $target_entity_type_id,
          $target_bundle,
          $item['fields'] ?? [],
          $item['defaults'] ?? [],
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Removes references to deleted fields and bundles from the template.
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = parent::onDependencyRemoval($dependencies);

    foreach ($dependencies['config'] ?? [] as $entity) {
      if ($entity instanceof FieldConfigInterface) {
        $changed = $this->stripField(
          $this->fields,
          $this->defaults,
          'node',
          $this->content_type,
          $entity->getTargetEntityTypeId(),
          $entity->getTargetBundle(),
          $entity->getName(),
        ) || $changed;
        continue;
      }
      if ($entity instanceof ConfigEntityInterface) {
        $bundle_of = $entity->getEntityType()->getBundleOf();
        if ($bundle_of !== NULL) {
          $changed = $this->stripItemsOfBundle($this->fields, $bundle_of, (string) $entity->id()) || $changed;
        }
      }
    }

    return $changed;
  }

  /**
   * Recursively removes a field from a fields/defaults definition pair.
   *
   * @param array<string, mixed> $fields
   *   The field definitions of the current level, altered by reference.
   * @param array<string, mixed> $defaults
   *   The default definitions of the current level, altered by reference.
   * @param string $host_entity_type_id
   *   The entity type the current level describes.
   * @param string $host_bundle
   *   The bundle the current level describes.
   * @param string $target_entity_type_id
   *   The entity type of the deleted field.
   * @param string $target_bundle
   *   The bundle of the deleted field.
   * @param string $field_name
   *   The deleted field name.
   *
   * @return bool
   *   TRUE if anything was removed.
   */
  private function stripField(
    array &$fields,
    array &$defaults,
    string $host_entity_type_id,
    string $host_bundle,
    string $target_entity_type_id,
    string $target_bundle,
    string $field_name,
  ): bool {
    $changed = FALSE;

    if ($host_entity_type_id === $target_entity_type_id && $host_bundle === $target_bundle) {
      if (array_key_exists($field_name, $fields)) {
        unset($fields[$field_name]);
        $changed = TRUE;
      }
      if (array_key_exists($field_name, $defaults)) {
        unset($defaults[$field_name]);
        $changed = TRUE;
      }
    }

    foreach ($fields as &$field_config) {
      if (!is_array($field_config) || empty($field_config['items']) || !is_array($field_config['items'])) {
        continue;
      }
      foreach ($field_config['items'] as &$item) {
        if (!is_array($item) || empty($item['entity_type']) || empty($item['bundle']) || !is_array($item['fields'] ?? NULL)) {
          continue;
        }
        $item_defaults = [];
        $changed = $this->stripField(
          $item['fields'],
          $item_defaults,
          $item['entity_type'],
          $item['bundle'],
          $target_entity_type_id,
          $target_bundle,
          $field_name,
        ) || $changed;
      }
    }

    return $changed;
  }

  /**
   * Recursively removes reference items targeting a deleted bundle.
   *
   * @param array<string, mixed> $fields
   *   The field definitions of the current level, altered by reference.
   * @param string $entity_type_id
   *   The entity type of the deleted bundle.
   * @param string $bundle
   *   The deleted bundle machine name.
   *
   * @return bool
   *   TRUE if anything was removed.
   */
  private function stripItemsOfBundle(array &$fields, string $entity_type_id, string $bundle): bool {
    $changed = FALSE;

    foreach ($fields as &$field_config) {
      if (!is_array($field_config) || empty($field_config['items']) || !is_array($field_config['items'])) {
        continue;
      }

      $kept = [];
      foreach ($field_config['items'] as $item) {
        if (
          is_array($item) &&
          ($item['entity_type'] ?? NULL) === $entity_type_id &&
          ($item['bundle'] ?? NULL) === $bundle
        ) {
          $changed = TRUE;
          continue;
        }
        $kept[] = $item;
      }
      $field_config['items'] = $kept;

      foreach ($field_config['items'] as &$item) {
        if (is_array($item) && is_array($item['fields'] ?? NULL)) {
          $changed = $this->stripItemsOfBundle($item['fields'], $entity_type_id, $bundle) || $changed;
        }
      }
    }

    return $changed;
  }

}
