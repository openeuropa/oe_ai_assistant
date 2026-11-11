<?php

declare(strict_types=1);

namespace Drupal\schema_api;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Drupal\schema_api\Context\FieldContext;
use Drupal\schema_api\Context\ItemContext;
use Drupal\schema_api\Context\PropertyContext;
use Drupal\schema_api\Event\FieldItemSchemaEvent;
use Drupal\schema_api\Event\FieldSchemaEvent;
use Drupal\schema_api\Event\PropertySchemaEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Walks an entity bundle and lets the builders describe what it finds.
 *
 * This class assembles the nesting, a bundle holding fields holding items
 * holding properties, and writes no keyword of its own below the envelope.
 * Everything else comes from a builder subscribed to one of the three schema
 * events.
 */
final class SchemaApi implements SchemaApiInterface {

  /**
   * The bundles being walked, to stop one that reaches itself.
   */
  private array $walking = [];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(string $entityTypeId, string $bundle): array {
    // A builder may walk into a referenced bundle, which can lead back here.
    // Returning nothing lets the builder that asked drop the branch, and
    // keeps a bundle that reaches itself from recurring without end.
    $key = $entityTypeId . ':' . $bundle;
    if (isset($this->walking[$key])) {
      return [];
    }
    $this->walking[$key] = TRUE;

    try {
      return $this->bundle($entityTypeId, $bundle);
    }
    finally {
      unset($this->walking[$key]);
    }
  }

  /**
   * Walks the fields of one bundle.
   */
  private function bundle(string $entityTypeId, string $bundle): array {
    $properties = [];
    $required = [];

    foreach ($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle) as $name => $definition) {
      $context = new FieldContext(
        $entityTypeId,
        $bundle,
        $definition,
        $this->options($definition->getConstraints()),
      );
      $field = $this->field($context);
      if ($field === NULL) {
        continue;
      }
      $properties[$name] = $field;
      if ($definition->isRequired()) {
        $required[] = $name;
      }
    }

    $schema = [
      'type' => 'object',
      'properties' => $properties,
      'additionalProperties' => FALSE,
    ];
    if ($required !== []) {
      $schema['required'] = $required;
    }

    return $schema;
  }

  /**
   * Builds one field, then hands it to the builders.
   *
   * @return array|null
   *   The schema of the field, or NULL when a builder left it out.
   */
  private function field(FieldContext $context): ?array {
    $event = new FieldSchemaEvent($context, ['items' => $this->item($context)]);
    $this->eventDispatcher->dispatch($event);

    return $event->isSkipped() ? NULL : $event->getSchema();
  }

  /**
   * Builds the properties of one item, then hands them to the builders.
   */
  private function item(FieldContext $context): array {
    $itemDefinition = $context->fieldDefinition->getItemDefinition();
    $constraints = $this->itemConstraints($context->fieldDefinition);
    $properties = [];

    foreach ($itemDefinition->getPropertyDefinitions() as $name => $definition) {
      $properties[$name] = $this->property(new PropertyContext(
        $context->entityTypeId,
        $context->bundle,
        $context->fieldDefinition,
        $name,
        $definition,
        $this->options($definition->getConstraints() + ($constraints[$name] ?? [])),
      ));
    }

    $event = new FieldItemSchemaEvent(
      new ItemContext(
        $context->entityTypeId,
        $context->bundle,
        $context->fieldDefinition,
        $this->options($itemDefinition->getConstraints()),
      ),
      ['properties' => $properties],
    );
    $this->eventDispatcher->dispatch($event);

    return $event->getSchema();
  }

  /**
   * Hands one property to the builders, starting from nothing.
   */
  private function property(PropertyContext $context): array {
    $event = new PropertySchemaEvent($context, []);
    $this->eventDispatcher->dispatch($event);

    return $event->getSchema();
  }

  /**
   * Collects the constraints a field item puts on its own properties.
   *
   * Field types such as StringItem and NumericItemBase declare their limits
   * in a ComplexData constraint on the item, keyed by property name, and
   * none of it shows up on the property definitions. Building the item is
   * the only way to read them, and it needs the definition alone, no entity
   * and no stored values.
   */
  private function itemConstraints(FieldDefinitionInterface $definition): array {
    $item = $this->typedDataManager->createInstance('field_item:' . $definition->getType(), [
      'data_definition' => $definition->getItemDefinition(),
      'name' => NULL,
      'parent' => NULL,
    ]);

    $byProperty = [];
    foreach ($item->getConstraints() as $constraint) {
      if (!$constraint instanceof ComplexDataConstraint) {
        continue;
      }
      foreach ($constraint->properties as $name => $constraints) {
        foreach ($constraints as $constraintName => $options) {
          // NumericItemBase adds one ComplexData per bound, so Range turns
          // up twice, with a min on one and a max on the other.
          // ComplexDataConstraint has already turned each into an object,
          // so we read the options back off it and keep only the ones that
          // are set, which lets the two merge.
          $options = array_filter(
            get_object_vars($options),
            static fn ($value) => $value !== NULL,
          );
          $byProperty[$name][$constraintName] = $options + ($byProperty[$name][$constraintName] ?? []);
        }
      }
    }

    return $byProperty;
  }

  /**
   * Reduces a constraint list to plain option arrays keyed by name.
   *
   * A constraint declared with no options is stored as NULL, and a builder
   * asking for one that is absent is answered with NULL too, so the two have
   * to be told apart before the builders see them.
   */
  private function options(array $constraints): array {
    return array_map(
      static fn ($options) => is_array($options) ? $options : [],
      $constraints,
    );
  }

}
