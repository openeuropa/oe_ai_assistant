<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Neuron\Drafting\DraftingWorkflow;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use NeuronAI\Observability\EventBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the Neuron agents and workflows on top of the drupal/ai setup.
 *
 * Providers come from the drupal/ai default provider per operation type,
 * prompts and tools from the ai_agents config entities, and every run
 * records its turns in the conversation and logs its events.
 */
final class AgentFactory {

  public function __construct(
    #[Autowire(service: 'ai.provider')]
    private readonly AiProviderPluginManager $providerManager,
    #[Autowire(service: 'plugin.manager.ai_agents')]
    private readonly AiAgentManager $agentManager,
    #[Autowire(service: 'plugin.manager.ai.function_calls')]
    private readonly FunctionCallPluginManager $functionCallManager,
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
   * @param string $systemPrompt
   *   The complete router instructions.
   * @param array $fixedToolContexts
   *   Context values forced on the tools, keyed by tool name then context.
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The stream on which every inference is framed as a step.
   */
  public function router(EntityInterface $host, string $systemPrompt, array $fixedToolContexts, UiMessageStreamInterface $stream): RouterAgent {
    $tools = [];
    $functions = $this->agentManager->createInstance('oe_drafting_router')->getFunctions();
    foreach ($functions['normalized'] ?? [] as $definition) {
      $fixed = $fixedToolContexts[$definition->getName()] ?? [];
      // The model must not supply, or be steered into supplying, a value the
      // caller pins.
      foreach (array_keys($fixed) as $propertyName) {
        $definition->unsetProperty($propertyName);
      }
      $tools[] = new FunctionCallTool($definition, $fixed, $this->functionCallManager, $this->logger);
    }
    $tools[] = new DraftContentTool();

    [$provider, $providerId, $modelId] = $this->provider('chat_with_tools', ['drafting']);
    $agent = new RouterAgent($provider, $systemPrompt, $tools, [DraftContentTool::NAME]);
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
    $systemPrompt = $this->agentManager->createInstance('oe_content_drafter')->getSystemPrompt();
    if ($contextPrompt !== '') {
      $systemPrompt .= "\n\n" . $contextPrompt . "\n";
    }

    [$provider, $providerId, $modelId] = $this->provider('chat', ['drafting', $groupId]);
    $agent = new ContentDrafterAgent($provider, $systemPrompt);
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
      $agent->observe(new TranscriptObserver($this->logger, $this->messageRecorder, $host, $groupId, $parent, $systemPrompt));
    }
    return $agent;
  }

  /**
   * Builds the workflow that drafts every group and consolidates the fields.
   *
   * @param array $groups
   *   The schema groups to draft.
   * @param string $conversationContext
   *   The conversation so far, as role-prefixed lines.
   * @param callable $draftGroup
   *   Drafts one group, called with the step id, the schema slice and the
   *   task prompt, and returning the decoded field values.
   */
  public function draftingWorkflow(array $groups, string $conversationContext, callable $draftGroup): DraftingWorkflow {
    $workflow = new DraftingWorkflow($groups, $conversationContext, $draftGroup);
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
