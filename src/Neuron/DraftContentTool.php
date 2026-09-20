<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use NeuronAI\Tools\Tool;

/**
 * Signal tool the router calls when it is ready to draft.
 *
 * It ends the router run: the drafting workflow takes over from there.
 */
final class DraftContentTool extends Tool {

  public const NAME = 'draft_content';

  public function __construct() {
    parent::__construct(
      self::NAME,
      'Signal that you are ready to generate the content draft.'
      . ' Call this after you have gathered enough information'
      . ' from the user. The system will generate field values'
      . ' automatically using sub-agents.',
    );
  }

  /**
   * Returns a fixed acknowledgement; the result never reaches the model.
   */
  public function __invoke(): string {
    return 'ok';
  }

}
