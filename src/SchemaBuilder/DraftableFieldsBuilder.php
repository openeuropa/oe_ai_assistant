<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Leaves out the fields an editor never fills in by hand.
 *
 * A bundle carries two dozen base fields that Drupal keeps for itself, such
 * as the identifiers, the timestamps and the publishing flags. Describing
 * them invites a value for each one, while the only two worth asking about
 * are the title and the bundle the payload is meant to be.
 */
#[FieldSchemaBuilder(
  weight: -50,
)]
final class DraftableFieldsBuilder extends FieldSchemaBuilderBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    // A field somebody configured on the bundle is there to be filled in.
    if (!$context->fieldDefinition instanceof BaseFieldDefinition) {
      return $schema;
    }

    $keys = $this->entityTypeManager->getDefinition($context->entityTypeId)->getKeys();
    $kept = array_filter([$keys['label'] ?? NULL, $keys['bundle'] ?? NULL]);

    return in_array($context->fieldDefinition->getName(), $kept, TRUE) ? $schema : NULL;
  }

}
