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
 * as the identifiers, the timestamps and the publishing flags. None of them
 * has a value worth asking for, and describing one invites an answer that is
 * thrown away.
 */
#[FieldSchemaBuilder(
  weight: -50,
)]
final class DraftableFieldsBuilder extends FieldSchemaBuilderBase {

  /**
   * The base fields kept besides the label and the bundle.
   *
   * These two carry a value this module pins, so they stay in the schema and
   * another builder states what the value is.
   */
  private const PINNED = ['langcode', 'moderation_state'];

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
    $kept = array_merge(
      array_filter([$keys['label'] ?? NULL, $keys['bundle'] ?? NULL]),
      self::PINNED,
    );

    return in_array($context->fieldDefinition->getName(), $kept, TRUE) ? $schema : NULL;
  }

}
