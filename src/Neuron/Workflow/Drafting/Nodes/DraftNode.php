<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Nodes;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftedEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\Events\DraftRequestEvent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Asks the drafter agent for the values of one field group.
 */
final class DraftNode extends Node {

  use DependencySerializationTrait;

  /**
   * The drafter, built once so a correction continues its conversation.
   */
  protected ?object $agent = NULL;

  /**
   * Builds the node.
   *
   * @param \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface $agents
   *   Builds the drafter.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the run belongs to.
   * @param string $group
   *   The field group to draft.
   * @param array $schema
   *   The JSON schema the drafter's provider carries.
   * @param string $run
   *   The run this node drafts for, which names the drafter's conversation.
   */
  public function __construct(
    protected NeuronAgentManagerInterface $agents,
    protected AiEditorialSessionInterface $session,
    protected string $group,
    protected array $schema,
    protected string $run,
  ) {}

  /**
   * Runs one turn of the drafter.
   *
   * This node streams the turn and drops the chunks. The drafter streams to
   * nobody, but a provider behind a gateway can refuse a call that sends
   * nothing until it finishes, so the stream keeps the call alive.
   *
   * @todo Fix this in oe_ai_provider_gpt_at_ec instead. The gateway answers a
   *   long non-streamed call with a 500, so the provider should always stream
   *   and keep the connection alive, whatever the caller asked for. This node
   *   can then call chat() plainly.
   */
  public function __invoke(DraftRequestEvent $event, WorkflowState $state): DraftedEvent {
    $this->agent ??= $this->agents->createAgent('group_drafter', [
      'session' => $this->session,
      'group' => $this->group,
      'schema' => $this->schema,
      'run' => $this->run,
    ]);

    $result = $this->agent->chat(new UserMessage($event->message), TRUE);

    return new DraftedEvent($result->getMessage()?->getContent() ?? '');
  }

}
