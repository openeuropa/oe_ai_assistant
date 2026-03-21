<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\OeAiAssistant;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oe_ai_assistant\Annotation\AiAssistantPlugin;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\OeAiAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Plugin\OeAiAssistant\Drafting\FieldSnapshotStreamer;
use Drupal\oe_ai_assistant\Service\ConversationHistory;
use Drupal\oe_ai_assistant\Service\DraftFieldMapper;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Drupal\oe_ai_assistant\Service\LlmLoopConfig;
use Drupal\oe_ai_assistant\Service\LlmStreamingLoop;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: thin orchestrator for AI-powered content drafting.
 *
 * Delegates prompt building to DraftingPromptBuilder, conversation
 * history to ConversationHistory, the agentic tool-call loop to
 * LlmStreamingLoop, and field streaming to FieldSnapshotStreamer.
 * SSE events are emitted through the AG-UI state manager from the
 * base class.
 */
#[AiAssistantPlugin(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with AG-UI streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly DraftFieldMapper $fieldMapper,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly AiProviderPluginManager $aiProvider,
    protected readonly UuidInterface $uuid,
    protected readonly LoggerInterface $logger,
    protected readonly ConversationHistory $conversationHistory,
    protected readonly LlmStreamingLoop $llmLoop,
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
      $container->get('entity_type.manager'),
      $container->get(DraftFieldMapper::class),
      $container->get('current_user'),
      $container->get('ai.provider'),
      $container->get('uuid'),
      $container->get('logger.factory')->get('oe_ai_assistant'),
      $container->get(ConversationHistory::class),
      $container->get(LlmStreamingLoop::class),
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
   * Streams AI chat responses via AG-UI SSE.
   */
  public function chat(Request $request): Response {
    $body = $this->decodeJsonBody($request);

    // Extract user message from AG-UI protocol format.
    $message = $body['message'] ?? '';
    if (empty($message) && !empty($body['messages'])) {
      $userMessages = array_filter(
        $body['messages'],
        fn($m) => ($m['role'] ?? '') === 'user',
      );
      $lastUserMessage = end($userMessages);
      $message = is_array($lastUserMessage['content'] ?? '')
        ? implode('', array_map(fn($p) => $p['text'] ?? '', $lastUserMessage['content']))
        : ($lastUserMessage['content'] ?? '');
    }

    $threadId = $body['threadId'] ?? '';
    $forwardedProps = $body['forwardedProps'] ?? [];
    $entityTypeId = $forwardedProps['entityTypeId'] ?? $body['entityTypeId'] ?? 'node';
    $bundle = $forwardedProps['bundle'] ?? $body['bundle'] ?? '';

    if (empty($message)) {
      throw new ActionException('invalid_request', 'Message is required.', 400);
    }

    // Parse [fields:name1,name2] tag from the user message as a hint
    // for which fields to stream progressively. This is set by the
    // frontend regenerate button. The LLM also declares changed_fields
    // in its tool call, which overrides this hint if present.
    $fieldsToStream = [];
    if (preg_match('/\[fields:([^\]]+)\]/', $message, $matches)) {
      $fieldsToStream = explode(',', $matches[1]);
    }

    // Build prompt, tools, and field index via the prompt builder.
    $systemPrompt = $this->promptBuilder->buildSystemPrompt($entityTypeId, $bundle);
    $tools = $this->promptBuilder->buildTools();
    $fieldIndex = $this->promptBuilder->buildFieldIndex($entityTypeId, $bundle);

    // Get provider and model defaults.
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $providerId = $defaults['provider_id'];
    $modelId = $defaults['model_id'];

    return $this->createSseResponse(function () use (
      $systemPrompt, $message, $threadId, $tools, $providerId, $modelId,
      $fieldIndex, $fieldsToStream,
    ) {
      set_time_limit(0);

      $state = $this->createAgUiState();
      $runId = $this->uuid->generate();
      $sseThreadId = !empty($threadId) ? $threadId : $this->uuid->generate();
      $messageId = $this->uuid->generate();

      $state->startRun($sseThreadId, $runId);

      try {
        // Load conversation history and detect first-draft vs regeneration.
        $history = $this->conversationHistory->load('oe_ai_drafting', $sseThreadId);
        $isFirstDraft = empty($history);
        $history[] = ['role' => 'user', 'content' => $message];

        // Mutable copy: the tool executor may override from changed_fields.
        $activeFieldsToStream = $fieldsToStream;

        // Build the tool executor closure for the LLM loop.
        $toolExecutor = function (array $toolCalls) use (
          $fieldIndex, &$activeFieldsToStream, $isFirstDraft,
        ): array {
          $results = [];
          foreach ($toolCalls as $toolCallId => $toolCall) {
            $name = $toolCall['name'] ?? '';
            $args = $toolCall['arguments'] ?? [];

            $result = match ($name) {
              'draft_content' => $this->toolDraftContent($args),
              default => ['error' => "Unknown tool: $name"],
            };

            $results[] = [
              'role' => 'tool',
              'content' => Json::encode($result),
              'tool_call_id' => $toolCallId,
            ];

            // If the LLM declared changed_fields, override the hint.
            if (!empty($result['changedFields'])) {
              $activeFieldsToStream = $result['changedFields'];
            }

            // Stream fields progressively via the snapshot streamer.
            $streamer = new FieldSnapshotStreamer($this->transporter);
            $streamer->stream(
              $result['fields'] ?? [],
              $fieldIndex,
              $activeFieldsToStream,
              $isFirstDraft,
            );
          }
          return $results;
        };

        // Run the agentic LLM tool-call loop.
        $config = new LlmLoopConfig(
          systemPrompt: $systemPrompt,
          conversationHistory: $history,
          tools: $tools,
          providerId: $providerId,
          modelId: $modelId,
          messageId: $messageId,
          toolExecutor: $toolExecutor,
        );
        $loopResult = $this->llmLoop->run($this->agUiState, $config);

        // Save updated conversation history.
        $updatedHistory = $loopResult->messages;
        if (!empty($loopResult->assistantText)) {
          $updatedHistory[] = [
            'role' => 'assistant',
            'content' => $loopResult->assistantText,
          ];
        }
        $this->conversationHistory->save('oe_ai_drafting', $sseThreadId, $updatedHistory);
      }
      catch (\Exception $e) {
        $this->logger->error('Error in drafting chat: @error', [
          '@error' => $e->getMessage(),
        ]);
        $state->errorRun($e->getMessage());
      }

      $state->finishRun();
    });
  }

  /**
   * Resets the conversation thread.
   */
  public function reset(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $threadId = $body['threadId'] ?? '';

    if (!empty($threadId)) {
      $this->conversationHistory->delete('oe_ai_drafting', $threadId);
    }

    return ['threadId' => $this->uuid->generate()];
  }

  /**
   * Creates a Drupal node from an approved draft.
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

  /**
   * Tool handler: draft_content.
   *
   * Extracts the fields and changed_fields from the LLM tool call
   * arguments and returns them for the tool executor to process.
   *
   * @param array $args
   *   The tool call arguments from the LLM.
   *
   * @return array
   *   Array with success, fields, changedFields, and message keys.
   */
  private function toolDraftContent(array $args): array {
    $fields = $args['fields'] ?? $args;
    $changedFields = [];
    if (!empty($args['changed_fields']) && is_array($args['changed_fields'])) {
      $changedFields = $args['changed_fields'];
    }

    return [
      'success' => TRUE,
      'fields' => $fields,
      'changedFields' => $changedFields,
      'message' => 'Draft content generated with ' . count($fields) . ' fields.',
    ];
  }

}
