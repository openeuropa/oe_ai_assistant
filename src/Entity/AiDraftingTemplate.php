<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

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
  public function getDefaultsByBundle(): array {
    $byBundle = [];
    $this->walk(function (string $entityTypeId, string $bundle, array $fields, array $defaults) use (&$byBundle): void {
      if ($defaults !== []) {
        $byBundle[$entityTypeId][$bundle] = ($byBundle[$entityTypeId][$bundle] ?? []) + $defaults;
      }
    });
    return $byBundle;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(): ConstraintViolationListInterface {
    $violations = $this->getTypedData()->validate();
    $entityFieldManager = \Drupal::service(EntityFieldManagerInterface::class);

    $this->walk(function (string $entityTypeId, string $bundle, array $fields, array $defaults, string $path) use ($violations, $entityFieldManager): void {
      $covered = array_unique([...array_keys($fields), ...array_keys($defaults)]);

      foreach ($entityFieldManager->getFieldDefinitions($entityTypeId, $bundle) as $fieldName => $definition) {
        if (
          !$definition->isRequired() ||
          $definition->isComputed() ||
          $definition->isReadOnly() ||
          !$definition->isDisplayConfigurable('form') ||
          $definition->getDefaultValueLiteral() !== [] ||
          $definition->getDefaultValueCallback() ||
          in_array($fieldName, $covered, TRUE)
        ) {
          continue;
        }

        $fieldPath = $path === '' ? $fieldName : "$path > fields > $fieldName";
        $violations->add(new ConstraintViolation(
          sprintf(
            "Required field '%s' is missing from template fields or defaults on content type '%s'",
            $fieldPath,
            $bundle,
          ),
          "Required field '@field' is missing from template fields or defaults on content type '@bundle'",
          [
            '@field' => $fieldPath,
            '@bundle' => $bundle,
          ],
          $this,
          $path === '' ? "fields.$fieldName" : "$path.fields.$fieldName",
          NULL,
        ));
      }
    });

    return $violations;
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
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $entityFieldManager = \Drupal::service(EntityFieldManagerInterface::class);

    $this->walk(function (string $entityTypeId, string $bundle, array $fields, array $defaults) use ($entityFieldManager): void {
      $bundleEntityTypeId = $this->entityTypeManager()->getDefinition($entityTypeId)->getBundleEntityType();
      $bundleEntity = $bundleEntityTypeId
        ? $this->entityTypeManager()->getStorage($bundleEntityTypeId)->load($bundle)
        : NULL;
      if ($bundleEntity) {
        $this->addDependency('config', $bundleEntity->getConfigDependencyName());
      }

      $fieldDefinitions = $entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
      foreach (array_unique([...array_keys($fields), ...array_keys($defaults)]) as $fieldName) {
        $definition = $fieldDefinitions[$fieldName] ?? NULL;
        if ($definition instanceof FieldConfigInterface) {
          $this->addDependency('config', $definition->getConfigDependencyName());
        }
      }
    });

    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Removes deleted fields from every level's fields and defaults, and
   * reference items whose bundle was deleted.
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = parent::onDependencyRemoval($dependencies);

    $removedFields = [];
    $removedBundles = [];
    foreach ($dependencies['config'] ?? [] as $entity) {
      if ($entity instanceof FieldConfigInterface) {
        $removedFields[$entity->getTargetEntityTypeId()][$entity->getTargetBundle()][] = $entity->getName();
      }
      elseif ($entity instanceof ConfigEntityInterface && $entity->getEntityType()->getBundleOf() !== NULL) {
        $removedBundles[$entity->getEntityType()->getBundleOf()][] = (string) $entity->id();
      }
    }
    if ($removedFields === [] && $removedBundles === []) {
      return $changed;
    }

    $this->walk(function (string $entityTypeId, string $bundle, array &$fields, array &$defaults) use ($removedFields, $removedBundles, &$changed): void {
      foreach ($removedFields[$entityTypeId][$bundle] ?? [] as $fieldName) {
        if (array_key_exists($fieldName, $fields)) {
          unset($fields[$fieldName]);
          $changed = TRUE;
        }
        if (array_key_exists($fieldName, $defaults)) {
          unset($defaults[$fieldName]);
          $changed = TRUE;
        }
      }

      foreach ($fields as &$fieldConfig) {
        if (!is_array($fieldConfig) || !is_array($fieldConfig['items'] ?? NULL)) {
          continue;
        }
        $kept = array_values(array_filter(
          $fieldConfig['items'],
          static fn ($item) => !is_array($item)
            || !in_array($item['bundle'] ?? NULL, $removedBundles[$item['entity_type'] ?? ''] ?? [], TRUE),
        ));
        if (count($kept) !== count($fieldConfig['items'])) {
          $fieldConfig['items'] = $kept;
          $changed = TRUE;
        }
      }
      unset($fieldConfig);
    });

    return $changed;
  }

  /**
   * Calls a visitor on every entity level of the template, the node first.
   *
   * Levels are visited depth first, in document order. A level's reference
   * items are read after the visitor ran, so items it removes are skipped.
   *
   * @param callable $visit
   *   Receives the level's entity type ID, bundle, fields map, defaults map
   *   and property path. Declare the maps by reference to alter them.
   */
  private function walk(callable $visit): void {
    $this->walkLevel($visit, 'node', $this->content_type, $this->fields, $this->defaults, '');
  }

  /**
   * Visits one level and recurses into its reference items.
   */
  private function walkLevel(callable $visit, string $entityTypeId, string $bundle, array &$fields, array &$defaults, string $path): void {
    $visit($entityTypeId, $bundle, $fields, $defaults, $path);

    foreach ($fields as $fieldName => &$fieldConfig) {
      if (!is_array($fieldConfig) || !is_array($fieldConfig['items'] ?? NULL)) {
        continue;
      }
      foreach ($fieldConfig['items'] as $delta => &$item) {
        if (!is_array($item) || empty($item['entity_type']) || empty($item['bundle'])) {
          continue;
        }
        $itemFields = is_array($item['fields'] ?? NULL) ? $item['fields'] : [];
        $itemDefaults = is_array($item['defaults'] ?? NULL) ? $item['defaults'] : [];
        $this->walkLevel(
          $visit,
          $item['entity_type'],
          $item['bundle'],
          $itemFields,
          $itemDefaults,
          $path === '' ? "$fieldName.items[$delta]" : "$path > fields > $fieldName.items[$delta]",
        );
        if (is_array($item['fields'] ?? NULL)) {
          $item['fields'] = $itemFields;
        }
        if (is_array($item['defaults'] ?? NULL)) {
          $item['defaults'] = $itemDefaults;
        }
      }
      unset($item);
    }
    unset($fieldConfig);
  }

}
