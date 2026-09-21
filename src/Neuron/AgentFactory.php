<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Neuron\Agent\ContentDrafterAgent;
use Drupal\oe_ai_assistant\Neuron\Agent\RouterAgent;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Neuron\Observability\DrupalLogObserver;
use Drupal\oe_ai_assistant\Neuron\Observability\TranscriptObserver;
use Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\DrupalAiProvider;
use Drupal\oe_ai_assistant\Neuron\Tools\DraftContentTool;
use Drupal\oe_ai_assistant\Neuron\Workflow\Drafting\DraftingTurnWorkflow;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use NeuronAI\Observability\EventBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the Neuron agents and workflows on top of the drupal/ai providers.
 *
 * Providers come from the drupal/ai default provider per operation type,
 * and every run records its turns in the conversation and logs its events.
 */
final class AgentFactory {

  public function __construct(
    #[Autowire(service: 'ai.provider')]
    private readonly AiProviderPluginManager $providerManager,
    private readonly MessageRecorderInterface $messageRecorder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {
    // Neuron falls back to its Inspector APM observer for a run without
    // observers; keep every event in the Drupal log instead.
    EventBus::setDefaultObserver(new DrupalLogObserver($logger));
  }

  /**
   * Builds the router agent for one chat turn.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $contextPrompt
   *   Content type context appended to the router's instructions.
   * @param \NeuronAI\Tools\ToolInterface[] $tools
   *   The information tools offered to the model, pinned to the session.
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The stream on which every inference is framed as a step.
   */
  public function router(EntityInterface $host, string $contextPrompt, array $tools, UiMessageStreamInterface $stream): RouterAgent {
    [$provider, $providerId, $modelId] = $this->provider('chat_with_tools', ['drafting']);
    $agent = new RouterAgent($provider, $tools, $contextPrompt);
    $agent->setChatHistory(new ConversationChatHistory(
      $this->messageRecorder,
      $this->entityTypeManager->getStorage('ai_conversation_message'),
      $host,
      'orchestrator',
      $providerId,
      $modelId,
      authorId: (int) $this->currentUser->id(),
      unrecordedTools: [DraftContentTool::NAME],
    ));
    $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $host, 'orchestrator', stream: $stream));
    return $agent;
  }

  /**
   * Builds the drafter agent for one schema group.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn the drafter's rows nest under, or NULL to leave them unrecorded.
   * @param string $groupId
   *   The schema group id, stored as the agent id of the recorded rows.
   * @param string $contextPrompt
   *   Editorial context appended to the drafter's instructions, or empty.
   */
  public function drafter(EntityInterface $host, ?AiConversationMessageInterface $parent, string $groupId, string $contextPrompt): ContentDrafterAgent {
    [$provider, $providerId, $modelId] = $this->provider('chat', ['drafting', $groupId]);
    $agent = new ContentDrafterAgent($provider, $contextPrompt);
    if ($parent !== NULL) {
      $agent->setChatHistory(new ConversationChatHistory(
        $this->messageRecorder,
        $this->entityTypeManager->getStorage('ai_conversation_message'),
        $host,
        $groupId,
        $providerId,
        $modelId,
        $parent,
        load: FALSE,
      ));
      $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $host, $groupId, $parent, $agent->resolveInstructions()));
    }
    return $agent;
  }

  /**
   * Builds the workflow that runs one chat turn, from the message to the draft.
   *
   * @param \Drupal\oe_ai_assistant\Neuron\Agent\RouterAgent $router
   *   The router agent, with its conversation history attached.
   * @param string $message
   *   The user's message for this turn.
   * @param array $groups
   *   The schema groups to draft when the router asks for a draft.
   * @param \Closure $draftGroup
   *   Drafts one group, called with the step id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   * @param \Closure $versionDraft
   *   Versions and stores the fields, called with the consolidated fields and
   *   the parent turn, returning the result shaped {version, context, fields}.
   * @param \Closure $recordConfirmation
   *   Persists the confirmation text, called with that text.
   */
  public function draftingTurn(RouterAgent $router, string $message, array $groups, \Closure $draftGroup, \Closure $versionDraft, \Closure $recordConfirmation): DraftingTurnWorkflow {
    $workflow = new DraftingTurnWorkflow($router, $message, $groups, $draftGroup, $versionDraft, $recordConfirmation);
    $workflow->observe(new DrupalLogObserver($this->logger));
    return $workflow;
  }

  /**
   * Resolves the drupal/ai default provider of an operation type.
   *
   * @return array
   *   The Neuron provider, the drupal/ai provider id and the model id.
   */
  private function provider(string $operationType, array $tags): array {
    $defaults = $this->providerManager->getDefaultProviderForOperationType($operationType);
    $plugin = $this->providerManager->createInstance($defaults['provider_id']);
    return [
      new DrupalAiProvider($plugin, $defaults['model_id'], $tags),
      $defaults['provider_id'],
      $defaults['model_id'],
    ];
  }

}
