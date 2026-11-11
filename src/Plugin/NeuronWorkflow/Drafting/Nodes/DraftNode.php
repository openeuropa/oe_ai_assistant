<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\DraftingResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Events\DraftedEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Events\DraftRequestEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Asks the drafter agent for the values of one field group.
 */
final class DraftNode extends Node {

  /**
   * Builds the node.
   *
   * @param string $group
   *   The field group to draft.
   * @param array $schema
   *   The JSON schema the drafter's provider carries.
   * @param string $run
   *   The run this node drafts for, which names the drafter's conversation.
   */
  public function __construct(
    private readonly string $group,
    private readonly array $schema,
    private readonly string $run,
  ) {}

  /**
   * Runs one turn of the drafter.
   *
   * This node streams the turn and drops the chunks. The drafter streams to
   * nobody, but a provider behind a gateway can refuse a call that sends
   * nothing until it finishes, so the stream keeps the call alive.
   */
  public function __invoke(DraftRequestEvent $event, WorkflowState $state, DraftingResources $resources): DraftedEvent {
    $agent = $resources->agents->createAgent('group_drafter', [
      'session' => $resources->session,
      'group' => $this->group,
      'schema' => $this->schema,
      'run' => $this->run,
    ]);

    $result = $agent->chat(new UserMessage($event->message), TRUE);

    return new DraftedEvent($result->getMessage()?->getContent() ?? '');
  }

}
