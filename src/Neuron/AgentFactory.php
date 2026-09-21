<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Neuron\Agent\ContentDrafterAgent;
use Drupal\oe_ai_assistant\Neuron\Agent\DraftingAgent;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue;
use Drupal\oe_ai_assistant\Neuron\Observability\DrupalLogObserver;
use Drupal\oe_ai_assistant\Neuron\Observability\TranscriptObserver;
use Drupal\oe_ai_assistant\Neuron\Providers\DrupalAi\DrupalAiProvider;
use Drupal\oe_ai_assistant\Neuron\Tools\DraftCollector;
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
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $contextPrompt
   *   Content type context appended to the agent's instructions.
   * @param \Drupal\oe_ai_assistant\Neuron\Tools\DraftCollector $collector
   *   The collector of this turn's group results.
   * @param \Closure $drafter
   *   Drafts one group, called with the group id, the schema slice, the task
   *   prompt and the parent turn, and returning the decoded field values.
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue receiving every event of the run.
   */
  public function draftingAgent(EntityInterface $host, string $contextPrompt, DraftCollector $collector, \Closure $drafter, AgentEventQueue $events): DraftingAgent {
    [$provider, $providerId, $modelId] = $this->provider('chat_with_tools', ['drafting']);
    $agent = new DraftingAgent($provider, $contextPrompt, $host, $this->draftHistory, $collector, $drafter);
    $history = new ConversationChatHistory(
      $this->messageRecorder,
      $this->entityTypeManager->getStorage('ai_conversation_message'),
      $host,
      self::DRAFTING_AGENT_ID,
      $providerId,
      $modelId,
      authorId: (int) $this->currentUser->id(),
    );
    $agent->setChatHistory($history);
    $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $host, self::DRAFTING_AGENT_ID, $events, history: $history));
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
   * @param \Drupal\oe_ai_assistant\Neuron\Observability\AgentEventQueue $events
   *   The queue receiving every event of the run.
   */
  public function drafter(EntityInterface $host, ?AiConversationMessageInterface $parent, string $groupId, string $contextPrompt, AgentEventQueue $events): ContentDrafterAgent {
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
    }
    $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $host, $groupId, $events, $parent, $agent->resolveInstructions()));
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
