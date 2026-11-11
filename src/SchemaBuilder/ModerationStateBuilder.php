<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\SchemaBuilder;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\schema_api\Attribute\FieldSchemaBuilder;
use Drupal\schema_api\Builder\FieldSchemaBuilderBase;
use Drupal\schema_api\Context\FieldContext;

/**
 * Pins a draft to the state a new entity of the bundle starts in.
 *
 * The item is replaced rather than added to, so the schema asks for the one
 * state we accept and nothing else. Taking that state off the workflow keeps
 * the schema right for a bundle that runs a workflow of its own.
 */
#[FieldSchemaBuilder(
  entity_type: 'node',
  field_name: 'moderation_state',
  weight: 30,
)]
final class ModerationStateBuilder extends FieldSchemaBuilderBase {

  public function __construct(
    private readonly ModerationInformationInterface $moderationInformation,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function build(FieldContext $context, array $schema): ?array {
    $workflow = $this->moderationInformation->getWorkflowForEntityTypeAndBundle(
      $context->entityTypeId,
      $context->bundle,
    );

    // The field is on every bundle of a moderated entity type, even one no
    // workflow covers. Without a workflow there's no state to pin, so the
    // field is left out.
    if ($workflow === NULL) {
      return NULL;
    }

    $schema['items'] = [
      'type' => 'object',
      'properties' => [
        'value' => [
          'type' => 'string',
          // getInitialState() would answer this, but it throws without an
          // entity, which it uses to pick between published and draft for
          // one that already exists. A draft is always new, so the
          // configured state is the answer.
          'const' => $workflow->getTypePlugin()->getConfiguration()['default_moderation_state'] ?? 'draft',
        ],
      ],
      'additionalProperties' => FALSE,
      'required' => ['value'],
    ];
    $schema['maxItems'] = 1;

    return $schema;
  }

}
