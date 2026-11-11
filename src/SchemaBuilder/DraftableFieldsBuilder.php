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
 * as the identifiers, the timestamps and the publishing flags. There's
 * nothing to ask the model about them, and describing one only invites a
 * value we'd throw away.
 */
#[FieldSchemaBuilder(
  weight: -50,
)]
final class DraftableFieldsBuilder extends FieldSchemaBuilderBase {

  /**
   * The base fields kept besides the label and the bundle.
   *
   * We pin langcode and moderation_state rather than ask for them, so they
   * have to stay in the schema for LangcodeBuilder and ModerationStateBuilder
   * to state the value.
   */
  private const PINNED = ['langcode', 'moderation_state'];

  /**
   * The entity type whose plumbing is worth pinning.
   *
   * A draft is one node, and the language and workflow state are decided for
   * that node. A paragraph inside it inherits both, so naming them again on
   * every paragraph only gives a drafter more to echo back.
   */
  private const PINNED_ENTITY_TYPE = 'node';

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
    if ($context->entityTypeId === self::PINNED_ENTITY_TYPE) {
      $kept = array_merge($kept, self::PINNED);
    }

    return in_array($context->fieldDefinition->getName(), $kept, TRUE) ? $schema : NULL;
  }

}
