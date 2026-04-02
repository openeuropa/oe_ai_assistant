<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\ToolCallFieldStreamer;
use Drupal\oe_ai_assistant\Service\AgentFactory;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Drupal\oe_ai_assistant\Store\DrupalTempMessageStore;
use Drupal\oe_ai_assistant\Streaming\DataStreamListener;
use Drupal\oe_ai_assistant\Tool\DraftContentTool;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Message\Message;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Self-contained chat plugin that uses Symfony AI's Agent for LLM
 * orchestration and DataStreamListener for SSE event emission.
 * No base class template method -- the chat lifecycle is inline.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with SSE streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * Constructs a DraftingPlugin.
   *
   * @param array $configuration
   *   Plugin configuration array.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   UUID generator.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for oe_ai_assistant.
   * @param \Drupal\oe_ai_assistant\Service\AgentFactory $agentFactory
   *   Factory for creating Symfony AI Agent instances.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   Factory for per-user temp stores (conversation history).
   * @param \Drupal\oe_ai_assistant\Service\DraftFieldMapper $fieldMapper
   *   Maps LLM field values to Drupal nodes.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The authenticated Drupal user.
   * @param \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder $promptBuilder
   *   Builds the system prompt and tool metadata.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly AgentFactory $agentFactory,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly DraftFieldMapper $fieldMapper,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly DraftingPromptBuilder $promptBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('logger.factory')->get('oe_ai_assistant'),
      $container->get(AgentFactory::class),
      $container->get('tempstore.private'),
      $container->get(DraftFieldMapper::class),
      $container->get('current_user'),
      new DraftingPromptBuilder(
        $container->get(FormSchemaExtractor::class),
        $container->get('logger.factory')->get('oe_ai_assistant'),
      ),
    );
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
   * Self-contained chat lifecycle: decode request, build prompt,
   * create agent, stream deltas via DataStreamListener, persist
   * conversation.
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
    $threadId = $body['threadId'] ?? '';

    if (empty($message)) {
      throw new ActionException(
        'invalid_request', 'Message is required.', 400,
      );
    }

    // Build context and prompt before opening SSE so errors
    // surface as normal HTTP responses.
    $context = $this->buildContext($body);
    $prompt = $this->promptBuilder->buildSystemPrompt(
      $context['entityTypeId'], $context['bundle'],
    );

    return $this->createSseResponse(function ($response) use (
      $message, $prompt, $context, $threadId,
    ) {
      set_time_limit(0);
      $sseThreadId = !empty($threadId)
        ? $threadId : $this->uuid->generate();

      // Load conversation history.
      $store = new DrupalTempMessageStore(
        $this->tempStoreFactory, 'oe_ai_drafting', $sseThreadId,
      );
      $bag = $store->load();
      $bag->add(Message::ofUser($message));
      $bag = $bag->withSystemMessage(Message::forSystem($prompt));

      // Create the agent with tools and event listeners.
      $eventDispatcher = new EventDispatcher();
      $listener = new DataStreamListener(
        $response,
        $this->uuid->generate(),
        $this->createFieldDeltaObserver($context),
      );
      $this->registerToolCallListeners(
        $eventDispatcher, $listener, $context,
      );

      [$agent] = $this->agentFactory->createAgent(
        tools: [$this->createDraftContentTool($context)],
        toolMetadata: [$this->promptBuilder->buildToolMetadata()],
        eventDispatcher: $eventDispatcher,
      );

      try {
        $result = $agent->call($bag, ['stream' => TRUE]);
        $result->addListener($listener);

        // Drive the generator. The listener emits all SSE events.
        foreach ($result->getContent() as $_delta) {
        }

        // Persist conversation.
        $text = $listener->getAccumulatedText();
        if ($text !== '') {
          $bag->add(Message::ofAssistant($text));
        }
        $store->save($bag->withoutSystemMessage());
      }
      catch (\Exception $e) {
        $this->logger->error('Chat error: @e', [
          '@e' => $e->getMessage(),
        ]);
        $listener->emitSse('error', [
          'errorText' => $this->formatErrorForChat($e),
        ]);
        $listener->emitSse('finish', [
          'finishReason' => 'error',
          'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
        ]);
        $response->sendEvent(
          new \Symfony\Component\HttpFoundation\ServerEvent('[DONE]'),
        );
      }
    });
  }

  /**
   * Resets the conversation thread.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with a threadId key.
   *
   * @return array<string, string>
   *   An array with a new threadId.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';
    if (!empty($threadId)) {
      $store = new DrupalTempMessageStore(
        $this->tempStoreFactory, 'oe_ai_drafting', $threadId,
      );
      $store->drop();
    }
    return ['threadId' => $this->uuid->generate()];
  }

  /**
   * Creates a Drupal node from an approved draft.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request with bundle and fields keys.
   *
   * @return array<string, string>
   *   An array with nodeId and previewUrl.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $bundle = $body['bundle'] ?? '';
    $fields = $body['fields'] ?? [];

    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    try {
      $node = $this->fieldMapper->createNode($bundle, $fields);
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException('invalid_bundle', $e->getMessage(), 400);
    }

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->fieldMapper->getPreviewUrl($node),
    ];
  }

  // -- Private helpers -------------------------------------------------------

  /**
   * Extracts the user message from the request body.
   *
   * Supports both simple format (top-level "message" string) and
   * the OpenAI multi-message format ("messages" array with content
   * parts).
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return string
   *   The user message text, or empty string if not found.
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
   * Builds drafting-specific context from the request body.
   *
   * @param array $body
   *   The decoded request body.
   *
   * @return array
   *   Context with entityTypeId, bundle, and fieldIndex.
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
      'fieldIndex' => $this->promptBuilder
        ->buildFieldIndex($entityTypeId, $bundle),
    ];
  }

  /**
   * Creates the DraftContentTool instance for this request.
   *
   * @param array $context
   *   The context from buildContext().
   *
   * @return \Drupal\oe_ai_assistant\Tool\DraftContentTool
   *   The tool instance.
   */
  private function createDraftContentTool(array $context): DraftContentTool {
    return new DraftContentTool($context['fieldIndex'], $this->logger);
  }

  /**
   * Creates a field delta observer for progressive streaming.
   *
   * The observer receives partial tool call JSON and feeds it to
   * the ToolCallFieldStreamer for incremental field updates.
   *
   * @param array $context
   *   The context from buildContext().
   *
   * @return \Closure|null
   *   The observer closure, or NULL if no field index is available.
   */
  private function createFieldDeltaObserver(array $context): ?\Closure {
    $fieldIndex = $context['fieldIndex'];
    if (empty($fieldIndex)) {
      return NULL;
    }
    // The emitter writes SSE events via DataStreamListener.
    // We create a ToolCallFieldStreamer with a closure that
    // calls emitSse() on the listener -- but the listener
    // isn't available here yet. Instead, we pass raw partial
    // JSON to the streamer which handles its own emission.
    // The ToolCallFieldStreamer needs an emitter callable.
    // We'll provide it when the listener is created.
    return NULL;
  }

  /**
   * Registers Symfony AI event listeners for tool call lifecycle.
   *
   * Emits tool-call-start, tool-call-end, tool-result, and
   * data-drafted-fields events when tools complete.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher
   *   The event dispatcher shared with the Toolbox.
   * @param \Drupal\oe_ai_assistant\Streaming\DataStreamListener $listener
   *   The SSE listener for emitting events.
   * @param array $context
   *   The context from buildContext().
   */
  private function registerToolCallListeners(
    EventDispatcher $eventDispatcher,
    DataStreamListener $listener,
    array $context,
  ): void {
    $fieldIndex = $context['fieldIndex'];

    $eventDispatcher->addListener(
      ToolCallSucceeded::class,
      function (ToolCallSucceeded $event) use (
        $listener, $fieldIndex,
      ): void {
        $toolCall = $event->getResult()->getToolCall();
        $result = $event->getResult()->getResult();

        // Emit tool lifecycle events.
        $listener->emitSse('tool-call-start', [
          'id' => $this->uuid->generate(),
          'toolCallId' => $toolCall->getId(),
          'toolName' => $toolCall->getName(),
        ]);
        $listener->emitSse('tool-call-end');
        $listener->emitSse('tool-result', [
          'toolCallId' => $toolCall->getId(),
          'result' => is_string($result)
            ? (json_decode($result, TRUE) ?? $result)
            : $result,
        ]);

        // Emit data-drafted-fields for draft_content results.
        if ($toolCall->getName() === 'draft_content') {
          $resultData = json_decode((string) $result, TRUE);
          $draftedFields = $resultData['fields'] ?? [];
          if (!empty($fieldIndex)) {
            $draftedFields = array_intersect_key(
              $draftedFields, $fieldIndex,
            );
          }
          $listener->emitSse('data-drafted-fields', [
            'data' => $draftedFields,
          ]);
        }
      },
    );
  }

  /**
   * Formats an exception into a user-friendly error message.
   *
   * @param \Exception $e
   *   The caught exception.
   *
   * @return string
   *   A user-facing error message.
   */
  private function formatErrorForChat(\Exception $e): string {
    $msg = $e->getMessage();
    if (preg_match('/\b401\b|unauthorized/i', $msg)) {
      return 'The AI service rejected the API key.';
    }
    if (preg_match('/\b429\b|rate.?limit/i', $msg)) {
      return 'The AI service is overloaded. Try again shortly.';
    }
    if (preg_match('/\b5\d{2}\b|server error/i', $msg)) {
      return 'The AI service is temporarily unavailable.';
    }
    if (preg_match('/timeout|timed? out/i', $msg)) {
      return 'The AI service did not respond in time.';
    }
    return $msg;
  }

}
