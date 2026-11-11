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
 * The moderation state is computed, so nothing survives of it by the time
 * this runs and the whole item is written here. The state comes off the
 * workflow, which keeps the schema right for a bundle that names its own.
 */
#[FieldSchemaBuilder(
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

    // A bundle outside moderation still carries the field, and there is no
    // state to name for it.
    if ($workflow === NULL) {
      return NULL;
    }

    $schema['items'] = [
      'type' => 'object',
      'properties' => [
        'value' => [
          'type' => 'string',
          // getInitialState() needs an entity, to decide between published
          // and draft for one that already exists. A draft is always new,
          // so the configured state is the answer and no entity is needed.
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
