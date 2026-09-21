<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use NeuronAI\Tools\Tool;

/**
 * Tool returning the field groups of the content being drafted.
 *
 * The groups are the ones the turn drafts, resolved for the session's
 * content type and template before the run, so the model cannot read the
 * schema of anything else.
 */
final class GetContentSchemaTool extends Tool {

  public const NAME = 'get_content_schema';

  /**
   * GetContentSchemaTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Tools\DraftCollector $collector
   *   The collector holding the groups of this turn.
   */
  public function __construct(
    private readonly DraftCollector $collector,
  ) {
    parent::__construct(
      self::NAME,
      'Returns the field groups of the content being drafted, with their'
      . ' fields, types and descriptions. Call it to discover what needs'
      . ' to be drafted and what information is needed from the user.',
    );
  }

  /**
   * Returns the field groups as JSON.
   */
  public function __invoke(): string {
    return json_encode($this->collector->groups());
  }

}
