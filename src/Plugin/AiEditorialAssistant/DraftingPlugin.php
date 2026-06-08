<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Service\AgentFactory;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Store\DrupalTempMessageStore;
use Drupal\oe_ai_assistant\Tool\DraftContentTool;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Self-contained chat plugin that uses Symfony AI's Agent for LLM
 * orchestration and EventStreamResponse for SSE output. The chat
 * callback is a generator that yields ServerEvent objects --
 * EventStreamResponse drives the iteration.
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
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The authenticated Drupal user.
   * @param \Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder $promptBuilder
   *   Builds the system prompt and tool metadata.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   Used to detect moderated bundles and set the initial moderation state.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Used to load the node_type storage and validate the bundle.
   * @param \Drupal\oe_ai_assistant\Service\DraftEntityBuilder $draftEntityBuilder
   *   Builds an unsaved node from the LLM-produced fields map.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly AgentFactory $agentFactory,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly DraftingPromptBuilder $promptBuilder,
    protected readonly ModerationInformationInterface $moderationInformation,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DraftEntityBuilder $draftEntityBuilder,
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
      $container->get('current_user'),
      new DraftingPromptBuilder(
        $container->get(EntityJsonSchemaComposer::class),
        $container->get('logger.factory')->get('oe_ai_assistant'),
      ),
      $container->get('content_moderation.moderation_information'),
      $container->get('entity_type.manager'),
      $container->get(DraftEntityBuilder::class),
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
   * The callback is a generator that yields ServerEvent objects.
   * EventStreamResponse drives the iteration, flushing each event
   * to the client automatically.
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

    $context = $this->buildContext($body);
    $prompt = $this->promptBuilder->buildSystemPrompt(
      $context['entityTypeId'], $context['bundle'],
    );

    return $this->createSseResponse(function (EventStreamResponse $response) use (
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

      // Tool call events are emitted directly via $response
      // during stream iteration (inside AgentProcessor).
      $eventDispatcher = new EventDispatcher();
      $this->registerToolCallListeners(
        $eventDispatcher, $response, $context,
      );

      [$agent] = $this->agentFactory->createAgent(
        tools: [new DraftContentTool($context['fieldIndex'], $this->logger)],
        toolMetadata: [$this->promptBuilder->buildToolMetadata()],
        eventDispatcher: $eventDispatcher,
      );

      // Yield protocol lifecycle + text delta events.
      // EventStreamResponse drives the iteration.
      yield from $this->streamChat(
        $agent, $bag, $store,
      );
    });
  }

  /**
   * Streams the agent response as ServerEvent objects.
   *
   * This generator yields UI Message Stream Protocol events.
   * EventStreamResponse iterates and sends each one.
   *
   * @param \Symfony\AI\Agent\AgentInterface $agent
   *   The configured agent.
   * @param \Symfony\AI\Platform\Message\MessageBag $bag
   *   The conversation messages.
   * @param \Drupal\oe_ai_assistant\Store\DrupalTempMessageStore $store
   *   The conversation store.
   *
   * @return \Generator<\Symfony\Component\HttpFoundation\ServerEvent>
   *   ServerEvent objects for EventStreamResponse.
   */
  private function streamChat($agent, $bag, $store): \Generator {
    $messageId = $this->uuid->generate();
    yield $this->sse('start', ['messageId' => $messageId]);
    yield $this->sse('start-step');

    $accumulatedText = '';
    $textStarted = FALSE;
    $textId = bin2hex(random_bytes(8));

    try {
      $result = $agent->call($bag, ['stream' => TRUE]);

      foreach ($result->getContent() as $chunk) {
        // String chunks from AgentProcessor re-invocation.
        if (is_string($chunk)) {
          if (!$textStarted) {
            yield $this->sse('text-start', ['id' => $textId]);
            $textStarted = TRUE;
          }
          yield $this->sse('text-delta', ['textDelta' => $chunk]);
          $accumulatedText .= $chunk;
          continue;
        }

        if ($chunk instanceof TextDelta) {
          if (!$textStarted) {
            yield $this->sse('text-start', ['id' => $textId]);
            $textStarted = TRUE;
          }
          yield $this->sse('text-delta', [
            'textDelta' => $chunk->getText(),
          ]);
          $accumulatedText .= $chunk->getText();
        }
        elseif ($chunk instanceof ToolCallStart) {
          if ($textStarted) {
            yield $this->sse('text-end');
            $textStarted = FALSE;
            $textId = bin2hex(random_bytes(8));
          }
        }
        elseif ($chunk instanceof ToolInputDelta) {
          yield $this->sse('tool-call-delta', [
            'argsText' => $chunk->getPartialJson(),
          ]);
        }
      }

      // Close any open text segment.
      if ($textStarted) {
        yield $this->sse('text-end');
      }

      // Persist conversation.
      if ($accumulatedText !== '') {
        $bag->add(Message::ofAssistant($accumulatedText));
      }
      $store->save($bag->withoutSystemMessage());

      yield $this->sse('finish-step', [
        'finishReason' => 'stop',
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
        'isContinued' => FALSE,
      ]);
      yield $this->sse('finish', [
        'finishReason' => 'stop',
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Chat error: @e', [
        '@e' => $e->getMessage(),
      ]);
      yield $this->sse('error', [
        'errorText' => $this->formatErrorForChat($e),
      ]);
      yield $this->sse('finish', [
        'finishReason' => 'error',
        'usage' => ['inputTokens' => 0, 'outputTokens' => 0],
      ]);
    }

    yield new ServerEvent('[DONE]');
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
   * Saves a drafted node built from the LLM-produced fields map.
   *
   * The unsaved node (and any inline children) come from DraftEntityBuilder;
   * $node->save() commits the whole tree transactionally via the parent's
   * preSave chain.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The save request.
   *
   * @return array<string, string>
   *   An array with `nodeId` and `previewUrl`.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   - 'invalid_bundle' (400) if the bundle does not exist.
   *   - 'forbidden' (403) if the current user lacks create permission.
   *   - 'invalid_payload' (400) if the builder rejects the payload.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $bundle = $body['bundle'] ?? '';
    $fields = $body['fields'] ?? [];

    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      throw new ActionException('invalid_bundle',
        sprintf('Content type "%s" does not exist.', $bundle), 400);
    }

    if (!$this->currentUser->hasPermission("create $bundle content")) {
      throw new ActionException(
        'forbidden',
        sprintf('You do not have permission to create %s content.', $bundle),
        403,
      );
    }

    try {
      /** @var \Drupal\node\NodeInterface $node */
      $node = $this->draftEntityBuilder->fromLlmFields('node', $bundle, $fields);
    }
    catch (\Throwable $e) {
      // Log the raw exception for operators; return a stable, generic message
      // to the client to avoid leaking internals over HTTP.
      $this->logger->error('Failed to build draft entity: @e', [
        '@e' => (string) $e,
      ]);
      throw new ActionException(
        'invalid_payload',
        'The submitted draft payload could not be processed. See the system log for details.',
        400,
      );
    }

    $node->setOwnerId((int) $this->currentUser->id());
    if ($this->moderationInformation->isModeratedEntity($node)) {
      $node->set('moderation_state', 'draft');
    }
    else {
      $node->setPublished(FALSE);
    }
    // Atomic save: the parent's preSave chain saves any inline children in
    // the same transaction.
    $node->save();

    return [
      'nodeId' => (string) $node->id(),
      'previewUrl' => $this->buildPreviewUrl($node),
    ];
  }

  /**
   * Builds the preview URL for a freshly saved node.
   *
   * Moderated entities expose a /latest revision route; unmoderated nodes
   * use the canonical route.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The newly saved node.
   *
   * @return string
   *   Relative URL pointing at either the latest revision or the canonical
   *   page.
   */
  private function buildPreviewUrl(NodeInterface $node): string {
    return $this->moderationInformation->isModeratedEntity($node)
      ? '/node/' . $node->id() . '/latest'
      : '/node/' . $node->id();
  }

  /**
   * Creates a ServerEvent for a UI Message Stream Protocol event.
   *
   * @param string $type
   *   The event type.
   * @param array<string, mixed> $data
   *   Additional payload fields.
   *
   * @return \Symfony\Component\HttpFoundation\ServerEvent
   *   The SSE event.
   */
  private function sse(string $type, array $data = []): ServerEvent {
    $data['type'] = $type;
    return new ServerEvent(
      json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    );
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
   * Registers Symfony AI event listeners for tool call lifecycle.
   *
   * These fire during stream iteration (inside AgentProcessor)
   * and emit SSE events directly via the response -- they cannot
   * yield because they run inside the generator, not at the top
   * level.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $eventDispatcher
   *   The event dispatcher shared with the Toolbox.
   * @param \Symfony\Component\HttpFoundation\EventStreamResponse $response
   *   The SSE response for direct event emission.
   * @param array $context
   *   The context from buildContext().
   */
  private function registerToolCallListeners(
    EventDispatcher $eventDispatcher,
    EventStreamResponse $response,
    array $context,
  ): void {
    $fieldIndex = $context['fieldIndex'];

    $eventDispatcher->addListener(
      ToolCallSucceeded::class,
      function (ToolCallSucceeded $event) use (
        $response, $fieldIndex,
      ): void {
        $toolCall = $event->getResult()->getToolCall();
        $result = $event->getResult()->getResult();

        // Tool lifecycle events.
        $response->sendEvent($this->sse('tool-call-start', [
          'id' => $this->uuid->generate(),
          'toolCallId' => $toolCall->getId(),
          'toolName' => $toolCall->getName(),
        ]));
        $response->sendEvent($this->sse('tool-call-end'));
        $response->sendEvent($this->sse('tool-result', [
          'toolCallId' => $toolCall->getId(),
          'result' => is_string($result)
            ? (json_decode($result, TRUE) ?? $result)
            : $result,
        ]));

        // Emit data-drafted-fields for draft_content results.
        if ($toolCall->getName() === 'draft_content') {
          $resultData = json_decode((string) $result, TRUE);
          $draftedFields = $resultData['fields'] ?? [];
          if (!empty($fieldIndex)) {
            $draftedFields = array_intersect_key(
              $draftedFields, $fieldIndex,
            );
          }
          $response->sendEvent($this->sse('data-drafted-fields', [
            'data' => $draftedFields,
          ]));
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
