<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\DraftingOrchestratorInterface;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\ToolExecutionLoopInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Drupal\oe_ai_assistant\Store\ConversationStoreFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Uses a two-tool conversational flow:
 * 1. get_content_schema: LLM discovers available fields
 * 2. draft_content: LLM signals readiness, orchestrator dispatches
 *    sub-agents per field group.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with SSE streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The AI agent plugin manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected AiAgentManager $aiAgentManager;

  /**
   * The UI message stream service.
   *
   * @var \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface
   */
  protected UiMessageStreamInterface $uiMessageStream;

  /**
   * The conversation store factory.
   *
   * @var \Drupal\oe_ai_assistant\Store\ConversationStoreFactoryInterface
   */
  protected ConversationStoreFactoryInterface $conversationStoreFactory;

  /**
   * Logger channel for oe_ai_assistant.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The JSON Schema composer service.
   *
   * @var \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer
   */
  protected EntityJsonSchemaComposer $schemaComposer;

  /**
   * The draft saver service.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftSaverInterface
   */
  protected DraftSaverInterface $draftSaver;

  /**
   * The tool execution loop service.
   *
   * @var \Drupal\oe_ai_assistant\Service\ToolExecutionLoopInterface
   */
  protected ToolExecutionLoopInterface $toolLoop;

  /**
   * The orchestrator for sub-agent dispatch.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftingOrchestratorInterface
   */
  protected DraftingOrchestratorInterface $orchestrator;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->aiProviderManager = $container->get('ai.provider');
    $instance->aiAgentManager = $container->get('plugin.manager.ai_agents');
    $instance->uiMessageStream = $container->get(UiMessageStreamInterface::class);
    $instance->conversationStoreFactory = $container->get(ConversationStoreFactoryInterface::class);
    $instance->logger = $container->get('logger.channel.oe_ai_assistant');
    $instance->schemaComposer = $container->get(EntityJsonSchemaComposer::class);
    $instance->draftSaver = $container->get(DraftSaverInterface::class);
    $instance->toolLoop = $container->get(ToolExecutionLoopInterface::class);
    $instance->orchestrator = $container->get(DraftingOrchestratorInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return [
      'chat' => $this->chat(...),
      'reset' => $this->reset(...),
      'save' => $this->save(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    return [
      'reset' => 'DraftingResetRequest',
      'save' => 'DraftingSaveRequest',
    ];
  }

  /**
   * Streams an AI chat response via SSE.
   *
   * Supports a multi-turn tool flow: the LLM can call
   * get_content_schema (executed by ai_agents), chat with the
   * user, then call draft_content to trigger sub-agent
   * orchestration.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a chat message body.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An SSE streaming response.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $message = $this->extractUserMessage($body);

    if (empty($message)) {
      throw new ActionException(
        'invalid_request', 'Message is required.', 400,
      );
    }

    $context = $this->buildContext($body);
    $threadId = $body['threadId'] ?? '';

    if (empty($threadId)) {
      $threadId = bin2hex(random_bytes(16));
    }
    $store = $this->conversationStoreFactory
      ->getStore('oe_ai_drafting', $threadId);

    // Load conversation history and append the user's message.
    $history = $store->load();
    $history[] = new ChatMessage('user', $message);

    // Load the router agent config entity for system prompt and
    // tools (get_content_schema is registered there).
    $router = $this->aiAgentManager->createInstance('oe_drafting_router');

    // Build the system prompt with schema groups appended.
    $systemPrompt = $this->buildSystemPrompt(
      $router->getSystemPrompt(), $context
    );

    // Collect tools: get_content_schema from agent config +
    // inline draft_content signal tool.
    $functions = $router->getFunctions();
    $tools = [];
    if (!empty($functions['normalized'])) {
      $tools = $functions['normalized'];
    }
    $tools[] = $this->buildDraftTool();

    // Resolve the provider for chat_with_tools.
    $defaults = $this->aiProviderManager
      ->getDefaultProviderForOperationType('chat_with_tools');
    $provider = $this->aiProviderManager
      ->createInstance($defaults['provider_id']);

    // Stream the response using UiMessageStream. The callback
    // delegates to ToolExecutionLoop which handles the multi-turn
    // tool call flow (call LLM, execute tools, repeat).
    return $this->uiMessageStream->respond(
      function (UiMessageStreamInterface $stream) use (
        $history, $store, $threadId, $context,
        $systemPrompt, $tools, $defaults, $provider,
      ): void {
        $stream->start();

        $stream->customEvent('data-thread-id', [
          'threadId' => $threadId,
        ]);

        // Run the tool execution loop. It handles streaming,
        // non-terminal tool execution (e.g. get_content_schema),
        // and stops when draft_content is called or the LLM
        // responds with text.
        $result = $this->toolLoop->run(
          $provider,
          $defaults['model_id'],
          $systemPrompt,
          $tools,
          $history,
          $stream,
        );

        if ($result->hasTerminalTool()
          && $result->terminalToolName === 'draft_content'
        ) {
          // Run the sub-agent orchestration.
          $this->orchestrator->run(
            $stream, $history,
            $context['entityTypeId'], $context['bundle']
          );
          $history[] = new ChatMessage('assistant',
            'Draft content generated.');
        }

        $store->save($history);
        $stream->finish($result->finishReason);
      }
    );
  }

  /**
   * Resets the conversation thread.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, string>
   *   A confirmation response.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';
    if (!empty($threadId)) {
      $this->conversationStoreFactory
        ->getStore('oe_ai_drafting', $threadId)
        ->drop();
    }
    return ['threadId' => bin2hex(random_bytes(16))];
  }

  /**
   * Saves a drafted node built from the LLM-produced fields map.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The save request.
   *
   * @return array<string, string>
   *   An array with `nodeId` and `previewUrl`.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   On invalid bundle, missing permission, or builder rejection.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    return $this->draftSaver->save(
      $body['bundle'] ?? '',
      $body['fields'] ?? [],
    );
  }

  /**
   * Builds the draft_content signal tool definition.
   *
   * No arguments. When the LLM calls this, the orchestrator takes
   * over and dispatches sub-agents per field group.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput
   *   The draft_content tool definition.
   */
  private function buildDraftTool(): ToolsFunctionInput {
    $tool = new ToolsFunctionInput();
    $tool->setName('draft_content');
    $tool->setDescription(
      'Signal that you are ready to generate the content draft.'
      . ' Call this after you have gathered enough information'
      . ' from the user. The system will generate field values'
      . ' automatically using sub-agents.'
    );
    return $tool;
  }

  /**
   * Extracts the user message from the request body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return string
   *   The user message text, or empty string.
   */
  private function extractUserMessage(array $body): string {
    $message = $body['message'] ?? '';
    if (!empty($message)) {
      return $message;
    }
    if (empty($body['messages'])) {
      return '';
    }
    $userMessages = array_filter(
      $body['messages'],
      fn($m) => ($m['role'] ?? '') === 'user',
    );
    $last = end($userMessages);
    if (is_array($last['content'] ?? '')) {
      return implode('', array_map(
        fn($p) => $p['text'] ?? '',
        $last['content'],
      ));
    }
    return $last['content'] ?? '';
  }

  /**
   * Builds drafting context from the request body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return array
   *   Context with entityTypeId and bundle.
   */
  private function buildContext(array $body): array {
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId']
      ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle']
      ?? $body['bundle'] ?? '';
    return [
      'entityTypeId' => $entityTypeId,
      'bundle' => $bundle,
    ];
  }

  /**
   * Builds the system prompt with content type context and schema.
   */
  private function buildSystemPrompt(string $basePrompt, array $context): string {
    $prompt = $basePrompt
      . "\n\nContent type context:\n"
      . "bundle: " . $context['bundle'] . "\n"
      . "entity_type_id: " . $context['entityTypeId'] . "\n";

    if (!empty($context['bundle'])) {
      $groups = $this->schemaComposer->splitSchemaIntoGroups(
        $context['entityTypeId'], $context['bundle']
      );
      $prompt .= "\nAvailable field groups:\n"
        . json_encode($groups, JSON_PRETTY_PRINT) . "\n";
    }

    return $prompt;
  }

}
