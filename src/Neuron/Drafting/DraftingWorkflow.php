<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Drafting;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/**
 * Drafts every schema group of a content type and consolidates the result.
 *
 * Plan, draft groups, consolidate: three nodes, each observable on its own.
 */
final class DraftingWorkflow extends Workflow {

  public const GROUPS = 'groups';
  public const CONTEXT = 'context';
  public const PLAN = 'plan';
  public const RESULTS = 'results';
  public const FIELDS = 'fields';

  /**
   * DraftingWorkflow constructor.
   *
   * @param array $groups
   *   The schema groups, each with groupId, label, fieldNames and schemaSlice.
   * @param string $conversationContext
   *   The conversation so far, as role-prefixed lines.
   * @param callable $draftGroup
   *   Drafts one group, called with the step id, the schema slice and the
   *   task prompt, and returning the decoded field values.
   */
  public function __construct(
    private readonly array $groups,
    private readonly string $conversationContext,
    private readonly mixed $draftGroup,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function state(): WorkflowState {
    return new WorkflowState([
      self::GROUPS => $this->groups,
      self::CONTEXT => $this->conversationContext,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function nodes(): array {
    return [
      new PlanNode(),
      new DraftGroupsNode($this->draftGroup),
      new ConsolidateNode(),
    ];
  }

  /**
   * Returns the consolidated fields once the workflow has run.
   */
  public function fields(): array {
    return $this->resolveState()->get(self::FIELDS, []);
  }

}
