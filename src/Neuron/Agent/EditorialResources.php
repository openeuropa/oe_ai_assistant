<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolRegistry;

/**
 * What the nodes of an editorial turn share.
 *
 * Neuron's own nodes read the provider, the conversation, the prompt and the
 * tools off AgentResources. DeferredCallsNode also starts the workflow that
 * answers a deferred call, so it needs the manager that builds one, the
 * session the call runs against, and somewhere to record a workflow that
 * could not answer. A run is built again for every segment, so they reach the
 * nodes from here rather than from a constructor.
 */
final class EditorialResources extends AgentResources {

  /**
   * Builds the resources.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $provider
   *   The model the turn talks to.
   * @param \NeuronAI\Chat\History\ChatHistory $history
   *   The conversation of the session.
   * @param \NeuronAI\Chat\Messages\SystemMessage $instructions
   *   The prompt the turn starts from.
   * @param \NeuronAI\Tools\ToolRegistry $tools
   *   The tools the model is offered.
   * @param array $context
   *   The content blocks every turn carries.
   * @param \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface $workflows
   *   Builds the workflow that answers a deferred call.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Records a workflow that could not answer its call.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the turn serves.
   */
  public function __construct(
    AIProviderInterface $provider,
    ChatHistory $history,
    SystemMessage $instructions,
    ToolRegistry $tools,
    array $context,
    public readonly NeuronWorkflowManagerInterface $workflows,
    public readonly LoggerChannelInterface $logger,
    public readonly ContentCreationSessionInterface $session,
  ) {
    parent::__construct($provider, $history, $instructions, $tools, $context);
  }

}
