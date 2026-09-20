<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use Drupal\oe_ai_assistant\Neuron\RouterAgent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/**
 * One chat turn of the drafting assistant, from the message to the draft.
 *
 * Route, plan, draft groups, consolidate, version: five nodes under one
 * workflow id, so a turn is one trace. The router and the drafters are
 * agents run inside the nodes.
 */
final class DraftingTurnWorkflow extends Workflow {

  public const MESSAGE = 'message';
  public const GROUPS = 'groups';
  public const CONTEXT = 'context';
  public const PARENT = 'parent';
  public const FINISH_REASON = 'finish_reason';
  public const PLAN = 'plan';
  public const RESULTS = 'results';
  public const FIELDS = 'fields';

  /**
   * DraftingTurnWorkflow constructor.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\RouterAgent $router
   *   The router agent, with its conversation history attached.
   * @param string $message
   *   The user's message for this turn.
   * @param array $groups
   *   The schema groups, each with groupId, label, fieldNames and schemaSlice.
   * @param \Closure $draftGroup
   *   Drafts one group, called with the step id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   * @param \Closure $versionDraft
   *   Versions and stores the fields, called with the consolidated fields and
   *   the parent turn, returning the result shaped {version, context, fields}.
   * @param \Closure $recordConfirmation
   *   Persists the confirmation text, called with that text.
   */
  public function __construct(
    private readonly RouterAgent $router,
    private readonly string $message,
    private readonly array $groups,
    private readonly \Closure $draftGroup,
    private readonly \Closure $versionDraft,
    private readonly \Closure $recordConfirmation,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function state(): WorkflowState {
    return new WorkflowState([
      self::MESSAGE => $this->message,
      self::GROUPS => $this->groups,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function nodes(): array {
    return [
      new RouteNode($this->router),
      new PlanNode(),
      new DraftGroupsNode($this->draftGroup),
      new ConsolidateNode(),
      new VersionNode($this->versionDraft, $this->recordConfirmation),
    ];
  }

  /**
   * Returns how the turn ended for the stream, once it has run.
   */
  public function finishReason(): string {
    return (string) $this->resolveState()->get(self::FINISH_REASON, 'stop');
  }

}
