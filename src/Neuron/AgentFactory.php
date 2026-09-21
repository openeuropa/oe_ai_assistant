<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Agent\FieldGroupAgent;
use Drupal\oe_ai_assistant\Neuron\Agent\DraftingAgent;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue;
use Drupal\oe_ai_assistant\Neuron\Observability\DrupalLogObserver;
use Drupal\oe_ai_assistant\Neuron\Observability\TranscriptObserver;
use Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\DrupalAiProvider;
use Drupal\oe_ai_assistant\Service\Drafting\DraftCollector;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Observability\EventBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the Neuron agents on top of the drupal/ai providers.
 *
 * Providers come from the drupal/ai default provider per operation type,
 * and every run records its turns in the conversation, logs its events and
 * queues them for the stream.
 */
final class AgentFactory {

  /**
   * The agent id stored on the rows of the drafting agent.
   */
  public const DRAFTING_AGENT_ID = 'drafting';

  public function __construct(
    #[Autowire(service: 'ai.provider')]
    private readonly AiProviderPluginManager $providerManager,
    private readonly MessageRecorderInterface $messageRecorder,
    private readonly DraftHistoryInterface $draftHistory,
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
   * Builds the drafting agent for one chat turn.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $contextPrompt
   *   Content type context appended to the agent's instructions.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftCollector $collector
   *   The collector of this turn's group results.
   * @param \Closure $drafter
   *   Drafts one group, called with the group id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue receiving every event of the run.
   */
  public function draftingAgent(AiEditorialSessionInterface $session, string $contextPrompt, DraftCollector $collector, \Closure $drafter, AgentEventQueue $events): DraftingAgent {
    [$provider, $providerId, $modelId] = $this->provider('chat_with_tools', ['drafting']);
    $agent = new DraftingAgent($provider, $contextPrompt, $session, $this->draftHistory, $collector, $drafter);
    $history = new ConversationChatHistory(
      $this->messageRecorder,
      $this->entityTypeManager->getStorage('ai_conversation_message'),
      $session,
      self::DRAFTING_AGENT_ID,
      $providerId,
      $modelId,
      authorId: (int) $this->currentUser->id(),
    );
    $agent->setChatHistory($history);
    $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $session, self::DRAFTING_AGENT_ID, $events, history: $history));
    return $agent;
  }

  /**
   * Builds the agent that drafts one field group.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn the drafter's rows nest under, or NULL to leave them unrecorded.
   * @param string $groupId
   *   The schema group id, stored as the agent id of the recorded rows.
   * @param array $schema
   *   The JSON schema of the group, which every answer must match.
   * @param string $contextPrompt
   *   Editorial context appended to the drafter's instructions, or empty.
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue receiving every event of the run.
   */
  public function fieldGroupAgent(AiEditorialSessionInterface $session, ?AiConversationMessageInterface $parent, string $groupId, array $schema, string $contextPrompt, AgentEventQueue $events): FieldGroupAgent {
    [$provider, $providerId, $modelId] = $this->provider('chat', ['drafting', $groupId]);
    $agent = new FieldGroupAgent($provider, $contextPrompt, $groupId, $schema);
    if ($parent !== NULL) {
      $agent->setChatHistory(new ConversationChatHistory(
        $this->messageRecorder,
        $this->entityTypeManager->getStorage('ai_conversation_message'),
        $session,
        $groupId,
        $providerId,
        $modelId,
        $parent,
        load: FALSE,
      ));
    }
    $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $session, $groupId, $events, $parent, $agent->resolveInstructions()));
    return $agent;
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
