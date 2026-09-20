<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Versions the consolidated draft and confirms it to the editor.
 *
 * The result is stored on the draft_content call so a reload can rebuild
 * the artifact, then streamed as the tool result. The confirmation names
 * the version, which is how it reaches the model on later turns.
 */
final class VersionNode extends Node {

  /**
   * VersionNode constructor.
   *
   * @param callable $versionDraft
   *   Versions and stores the fields, called with the consolidated fields and
   *   the parent turn, returning the result shaped {version, context, fields}.
   * @param callable $recordConfirmation
   *   Persists the confirmation text, called with that text.
   */
  public function __construct(
    private readonly mixed $versionDraft,
    private readonly mixed $recordConfirmation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function __invoke(VersionEvent $event, WorkflowState $state): \Generator {
    $fields = $state->get(DraftingTurnWorkflow::FIELDS, []);
    $result = ($this->versionDraft)($fields, $state->get(DraftingTurnWorkflow::PARENT));
    $state->set(DraftingTurnWorkflow::RESULT, $result);
    yield new DraftResultChunk($result);

    if ($fields !== []) {
      $confirmation = sprintf(
        'Draft %d generated with %d fields. Review the content on the right.',
        $result['version'],
        count($fields),
      );
      ($this->recordConfirmation)($confirmation);
      yield new ConfirmationChunk($confirmation);
    }

    return new StopEvent();
  }

}
