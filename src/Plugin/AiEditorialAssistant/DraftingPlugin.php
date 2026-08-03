<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DocumentSerializerInterface;
use Drupal\oe_ai_assistant\Service\DraftingOrchestratorInterface;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\ToolExecutionLoopInterface;
use Drupal\oe_ai_assistant\Service\UiMessageStreamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Uses a two-tool conversational flow:
 * 1. get_content_schema: LLM discovers available fields
 * 2. draft_content: LLM signals readiness, orchestrator dispatches
 *    sub-agents per field group.
 *
 * The conversation is scoped by an editorial session: the session hosts the
 * persisted ai_conversation_message rows, and its target content type drives
 * the drafting context. Turns are persisted by the message recorder, so any
 * user with access to the session sees the same conversation.
 */
#[AiEditorialAssistant(
  id: 'drafting',
  label: 'Drafting',
  description: 'AI-powered content drafting with SSE streaming.',
)]
class DraftingPlugin extends AiAssistantPluginBase {

  /**
   * The session field that stores the selected editorial tone.
   */
  protected const string TONE_FIELD = 'tone';

  /**
   * The session field that stores the selected drafting template.
   */
  private const string TEMPLATE_FIELD = 'template';

  /**
   * The AI agent plugin manager.
   *
   * @var \Drupal\ai_agents\PluginManager\AiAgentManager
   */
  protected AiAgentManager $aiAgentManager;

  /**
   * The drafting schema provider.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface
   */
  protected DraftingSchemaProviderInterface $schemaProvider;

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
   * The editorial tone context service.
   *
   * @var \Drupal\oe_ai_assistant\Service\AiEditorialContextInterface
   */
  protected AiEditorialContextInterface $aiEditorialContext;

  /**
   * The draft history reader.
   *
   * @var \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface
   */
  protected DraftHistoryInterface $draftHistory;

  /**
   * The file upload handler service.
   *
   * @var \Drupal\file\Upload\FileUploadHandlerInterface
   */
  protected FileUploadHandlerInterface $fileUploadHandler;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The document serializer.
   *
   * @var \Drupal\oe_ai_assistant\Service\DocumentSerializerInterface
   */
  protected DocumentSerializerInterface $documentSerializer;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->aiAgentManager = $container->get('plugin.manager.ai_agents');
    $instance->schemaProvider = $container->get(DraftingSchemaProviderInterface::class);
    $instance->draftSaver = $container->get(DraftSaverInterface::class);
    $instance->toolLoop = $container->get(ToolExecutionLoopInterface::class);
    $instance->orchestrator = $container->get(DraftingOrchestratorInterface::class);
    $instance->aiEditorialContext = $container->get(AiEditorialContextInterface::class);
    $instance->draftHistory = $container->get(DraftHistoryInterface::class);
    $instance->fileUploadHandler = $container->get(FileUploadHandlerInterface::class);
    $instance->fileSystem = $container->get('file_system');
    $instance->documentSerializer = $container->get(DocumentSerializerInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    // The base provides the shared get-messages action.
    return parent::getActionMap() + [
      'chat' => $this->chat(...),
      'reset' => $this->reset(...),
      'save' => $this->save(...),
      'set-tone' => $this->setTone(...),
      'set-template' => $this->setTemplate(...),
      'add-document' => $this->addDocument(...),
      'list-documents' => $this->listDocuments(...),
      'remove-document' => $this->removeDocument(...),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestSchemas(): array {
    // The base provides the get-messages schema.
    return parent::getRequestSchemas() + [
      'reset' => 'DraftingResetRequest',
      'save' => 'DraftingSaveRequest',
      'set-tone' => 'DraftingSetToneRequest',
      'set-template' => 'DraftingSetTemplateRequest',
      'add-document' => 'DraftingAddDocumentRequest',
      'list-documents' => 'DraftingListDocumentsRequest',
      'remove-document' => 'DraftingRemoveDocumentRequest',
    ];
  }

  /**
   * Streams an AI chat response via SSE.
   *
   * Supports a multi-turn tool flow: the LLM can call
   * get_content_schema (executed by ai_agents), chat with the
   * user, then call draft_content to trigger sub-agent
   * orchestration. History and every turn are scoped to the
   * editorial session named by the request.
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

    $session = $this->loadSession($body);
    $context = $this->buildContext($session);

    // Resolve the session's template and pin its id for the prompt, tool, and
    // orchestrator. An invalid stored template is a 400.
    try {
      $template = $this->schemaProvider->resolveTemplate(
        $context['entityTypeId'], $context['bundle'], $context['template']
      );
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException('invalid_request', $e->getMessage(), 400);
    }
    $context['template'] = $template?->id();

    // Resolve the full editorial context once: tone (id, label, prompt),
    // template (id, label) and documents (empty until the documents
    // backend lands). Sub-agents receive it for prompt injection and it
    // becomes the provenance snapshot of the produced draft.
    $editorialContext = $this->buildEditorialContext($session, $template);

    // Load the persisted transcript, then append the current user's message
    // for this turn's LLM call and persist it as a user turn.
    $history = $this->buildHistory($session);
    $history[] = new ChatMessage('user', $message);
    $this->messageRecorder->recordUser(
      $session, $message, (int) $this->currentUser->id()
    );

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

    // Persist each provider turn as it happens. The loop invokes this once
    // per response, so the assistant turn (including the terminal
    // draft_content turn) and its tool results are recorded without the loop
    // knowing about entities. Keep the last assistant turn so the drafted
    // fields can be attached to the triggering draft_content call afterwards.
    $lastAssistant = NULL;
    $recordTurn = function (ChatOutput $output, array $toolResults) use ($session, $defaults, &$lastAssistant): void {
      $lastAssistant = $this->messageRecorder->recordAssistant(
        $session, $output, 'orchestrator', $defaults['provider_id'], $defaults['model_id']
      );
      foreach ($toolResults as $toolMessage) {
        $this->messageRecorder->recordTool($session, $toolMessage->getText());
      }
    };

    // Stream the response using UiMessageStream. The callback
    // delegates to ToolExecutionLoop which handles the multi-turn
    // tool call flow (call LLM, execute tools, repeat).
    return $this->uiMessageStream->respond(
      function (UiMessageStreamInterface $stream) use (
        $history, $context, $systemPrompt, $tools,
        $defaults, $provider, $recordTurn, $session, &$lastAssistant,
        $editorialContext,
      ): void {
        $stream->start();

        // Run the tool execution loop. It handles streaming,
        // non-terminal tool execution (e.g. get_content_schema),
        // and stops when draft_content is called or the LLM
        // responds with text. The schema tool is pinned to the
        // entity type and bundle of the current editorial context:
        // the LLM cannot supply them, and any user-injected values
        // are overridden at execution time.
        $result = $this->toolLoop->run(
          $provider,
          $defaults['model_id'],
          $systemPrompt,
          $tools,
          $history,
          $stream,
          terminalToolNames: ['draft_content'],
          tags: ['drafting'],
          fixedToolContexts: [
            'get_content_schema' => [
              'entity_type_id' => $context['entityTypeId'],
              'bundle' => $context['bundle'],
              // The context definition is string-typed; NULL becomes ''.
              'template' => $context['template'] ?? '',
            ],
            // Pin the session so the model cannot read another session's
            // draft history.
            'get_draft_history' => [
              'session_id' => (string) $session->id(),
            ],
          ],
          recordTurn: $recordTurn,
        );

        if ($result->hasTerminalTool()
          && $result->terminalToolName === 'draft_content'
        ) {
          // Run the sub-agent orchestration and keep the consolidated fields.
          // The draft_content turn is the parent each sub-agent turn nests
          // under in the recorded transcript.
          $drafted = $this->orchestrator->run(
            $stream, $history,
            $context['entityTypeId'], $context['bundle'],
            $session, $lastAssistant,
            $editorialContext
          );
          // Version the draft and snapshot the context that produced it.
          // Prior drafts already carry a result; the current draft_content
          // call does not yet, so the count is the number of earlier drafts.
          $version = $this->draftHistory->countDrafts($session) + 1;
          $draftResult = [
            'version' => $version,
            'context' => $editorialContext->toSnapshot(),
            'fields' => $drafted,
          ];
          // Emit the draft_content tool call with its result so the card
          // appears live, matching what a reload rehydrates.
          $stream->toolCall('draft_content', [], $draftResult);
          // Record the versioned result on the draft_content call so the
          // transcript keeps a provenance trace that can repopulate the
          // artifact.
          if ($lastAssistant !== NULL) {
            $this->attachDraftResult($lastAssistant, $draftResult);
          }
          // Stream and record a confirmation so it survives a reload. The
          // draft name in the text is how the version reaches the model on
          // later turns (the reconstructed history carries text rows only).
          if ($drafted) {
            $confirmation = sprintf(
              'Draft %d generated with %d fields. Review the content on the right.',
              $version,
              count($drafted)
            );
            $stream->startStep('confirmation');
            $stream->textDelta($confirmation);
            $stream->finishStep('confirmation');
            $this->messageRecorder->recordAssistantText(
              $session, $confirmation, 'orchestrator'
            );
          }
        }

        $stream->finish($result->finishReason);
      }
    );
  }

  /**
   * Resets the conversation for the session.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, string>
   *   A confirmation response.
   */
  public function reset(Request $request): array {
    $session = $this->loadSession($this->decodeJsonBody($request));
    $this->entityTypeManager->getStorage('ai_conversation_message')
      ->deleteForHost($session);
    return ['status' => 'ok'];
  }

  /**
   * Saves one of the session's draft versions as an unpublished node.
   *
   * The request names a session and a draft version; the fields come from
   * the session's own draft history, so clients can never save field data
   * the session did not produce. The save is recorded as a durable
   * timeline event on the transcript.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The save request with `sessionId` and `version`.
   *
   * @return array<string, string>
   *   An array with `nodeId` and `previewUrl`.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   On an unknown version, missing permission, or builder rejection.
   */
  public function save(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $session = $this->loadSession($body);
    $version = (int) ($body['version'] ?? 0);

    $fields = $this->draftHistory->getDraftFields($session, $version);
    if ($fields === NULL) {
      throw new ActionException(
        'invalid_request',
        sprintf('Draft %d does not exist in this session.', $version),
        400,
      );
    }

    $result = $this->draftSaver->save($session->getContentType(), $fields);

    $this->messageRecorder->recordEvent(
      $session,
      sprintf('Draft %d saved as unpublished revision', $version),
      ['type' => 'save', 'version' => $version, 'nodeId' => $result['nodeId']],
      (int) $this->currentUser->id(),
    );

    return $result;
  }

  /**
   * Saves the selected drafting tone on the editorial session.
   *
   * The selected tone is stored on the session entity's tone field so chat
   * requests can use it without trusting tone values in the chat request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, string>
   *   A confirmation response.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the selected tone is invalid or not prompt-ready.
   */
  public function setTone(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $toneId = (string) ($body['toneId'] ?? '');

    // Validate before any write; getTone throws the same exception as
    // buildSelectedPrompt and also returns the label needed for the event.
    try {
      $tone = $this->aiEditorialContext->getTone($toneId);
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException(
        'invalid_context',
        $e->getMessage(),
        400,
      );
    }

    $session = $this->loadSession($body);
    $previous = $session->get(static::TONE_FIELD)->entity;
    $from = $previous
      ? ['id' => (string) $previous->id(), 'label' => (string) $previous->label()]
      : NULL;
    $session->set(static::TONE_FIELD, $toneId);
    $session->save();

    // Record the change as a durable timeline event; the summary names
    // both tones when there was a previous one. Re-selecting the current
    // tone is a no-op and must not record a misleading change event.
    $to = ['id' => $tone['id'], 'label' => $tone['label']];
    if ($from !== NULL && $from['id'] === $to['id']) {
      return ['status' => 'ok'];
    }
    $summary = $from === NULL
      ? sprintf('Tone changed to %s', $to['label'])
      : sprintf('Tone changed from %s to %s', $from['label'], $to['label']);
    $this->messageRecorder->recordEvent(
      $session, $summary,
      ['type' => 'tone', 'from' => $from, 'to' => $to],
      (int) $this->currentUser->id(),
    );

    return ['status' => 'ok'];
  }

  /**
   * Saves the selected drafting template on the editorial session.
   *
   * The template is mandatory; the field constraints reject an empty value, a
   * disabled template, a template for another bundle, or a missing one.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array<string, string>
   *   A confirmation response.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the selected template is not valid for the session.
   */
  public function setTemplate(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $templateId = (string) ($body['template'] ?? '');

    $session = $this->loadSession($body);
    $previous = $session->get(static::TEMPLATE_FIELD)->entity;
    $from = $previous
      ? ['id' => (string) $previous->id(), 'label' => (string) $previous->label()]
      : NULL;
    $session->set(static::TEMPLATE_FIELD, $templateId !== '' ? $templateId : NULL);

    $violations = $session->get(static::TEMPLATE_FIELD)->validate();
    if ($violations->count() > 0) {
      throw new ActionException(
        'invalid_request',
        (string) $violations[0]->getMessage(),
        400,
      );
    }

    $session->save();

    // Record the change as a durable timeline event. The field is mandatory
    // and validated above, so the referenced template always exists here.
    // Re-selecting the current template is a no-op and must not record a
    // misleading change event.
    $template = $session->get(static::TEMPLATE_FIELD)->entity;
    $to = ['id' => (string) $template->id(), 'label' => (string) $template->label()];
    if ($from !== NULL && $from['id'] === $to['id']) {
      return ['status' => 'ok'];
    }
    $summary = sprintf('Template changed to %s', $to['label']);
    $this->messageRecorder->recordEvent(
      $session, $summary,
      ['type' => 'template', 'from' => $from, 'to' => $to],
      (int) $this->currentUser->id(),
    );

    return ['status' => 'ok'];
  }

  /**
   * Attaches the versioned draft result to the draft_content tool call.
   *
   * Adds an uploaded document to the session document references.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming multipart request.
   *
   * @return array<string, array<string, string|array<string, string>>>
   *   The serialized document item.
   */
  public function addDocument(Request $request): array {
    $body = $request->request->all();
    $category = $this->resolveDocumentCategory((string) ($body['category'] ?? ''));
    $session = $this->loadSession($body);

    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile || !$upload->isValid()) {
      throw new ActionException(
        'invalid_request',
        'An uploaded file is required.',
        400,
      );
    }
    $managedFile = $this->saveUploadedDocument($upload, $category);
    $managedFile->setPermanent();
    $managedFile->save();

    $media = NULL;
    try {
      $media = $this->createDocumentMedia($managedFile, $upload, $category);
      $session->get($category['sessionField'])->appendItem([
        'target_id' => $media->id(),
      ]);
      $session->save();
    }
    catch (\Throwable $e) {
      if ($media instanceof MediaInterface) {
        $media->delete();
      }
      $managedFile->delete();
      throw $e;
    }

    return ['document' => $this->documentSerializer->serialize($media, ContextDocumentStorage::SOURCE_FIELD)];
  }

  /**
   * Lists documents referenced by the session.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array<string, array<int, array<string, string|array<string, string>>>>
   *   The serialized document items.
   */
  public function listDocuments(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $category = $this->resolveDocumentCategory((string) ($body['category'] ?? ''));
    $session = $this->loadSession($body);

    $documents = [];
    foreach ($session->get($category['sessionField'])->referencedEntities() as $media) {
      if ($media instanceof MediaInterface) {
        $documents[] = $this->documentSerializer->serialize($media, ContextDocumentStorage::SOURCE_FIELD);
      }
    }

    return ['documents' => $documents];
  }

  /**
   * Removes a referenced document from the session and deletes its entities.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array<string, string>
   *   A confirmation response.
   */
  public function removeDocument(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $session = $this->loadSession($body);
    $documentId = (string) ($body['documentId'] ?? '');

    if ($documentId === '') {
      throw new ActionException(
        'invalid_request',
        'A documentId is required.',
        400,
      );
    }

    $category = $this->resolveDocumentCategory(ContextDocumentStorage::CATEGORY);
    $field = $session->get($category['sessionField']);
    $referenced = FALSE;
    foreach ($field as $delta => $item) {
      if ((string) $item->target_id === $documentId) {
        $field->removeItem($delta);
        $referenced = TRUE;
        break;
      }
    }

    if (!$referenced) {
      throw new ActionException(
        'invalid_request',
        'The document is not referenced by this editorial session.',
        404,
      );
    }

    $media = $this->entityTypeManager->getStorage('media')->load($documentId);
    $session->save();
    if ($media instanceof MediaInterface) {
      $file = $this->getDocumentFile($media);
      $media->delete();
      $file?->delete();
    }

    return ['status' => 'ok'];
  }

  /**
   * Attaches the drafted fields as the result of the draft_content call.
   *
   * The result is the output of the draft_content tool, produced by the
   * orchestrator after the loop returns. Storing it on the tool call lets the
   * transcript render a clickable trace that repopulates the artifact.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The assistant turn that triggered drafting.
   * @param array $result
   *   The versioned draft result: {version, context, fields}.
   */
  private function attachDraftResult(AiConversationMessageInterface $message, array $result): void {
    $toolCalls = $message->getToolCalls();
    $found = FALSE;
    foreach ($toolCalls as &$call) {
      if (($call['function']['name'] ?? '') === 'draft_content') {
        $call['result'] = $result;
        $found = TRUE;
      }
    }
    unset($call);
    // Guarantee a draft_content trace even if the stream did not surface the
    // call in the reconstructed tool list.
    if (!$found) {
      $toolCalls[] = [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => $result,
      ];
    }
    $message->setToolCalls($toolCalls);
    $message->save();
  }

  /**
   * Resolves a document category into server-owned storage details.
   *
   * @param string $category
   *   The request category.
   *
   * @return array{category: string, sessionField: string, mediaBundle: string, sourceField: string}
   *   The resolved storage details.
   */
  private function resolveDocumentCategory(string $category): array {
    if ($category === ContextDocumentStorage::CATEGORY) {
      return [
        'category' => ContextDocumentStorage::CATEGORY,
        'sessionField' => ContextDocumentStorage::SESSION_FIELD,
        'mediaBundle' => ContextDocumentStorage::MEDIA_BUNDLE,
        'sourceField' => ContextDocumentStorage::SOURCE_FIELD,
      ];
    }

    throw new ActionException(
      'invalid_request',
      sprintf('Unsupported document category "%s".', $category),
      400,
    );
  }

  /**
   * Saves an uploaded document as a managed private file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   *   The resolved category storage details.
   *
   * @return \Drupal\file\FileInterface
   *   The managed file entity.
   */
  private function saveUploadedDocument(UploadedFile $upload, array $category): FileInterface {
    $directory = ContextDocumentStorage::UPLOAD_DIRECTORY;
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new ActionException(
        'upload_failed',
        'The private document directory could not be prepared.',
        500,
      );
    }

    try {
      $result = $this->fileUploadHandler->handleFileUpload(
        $this->createDrupalUploadedFile($upload),
        ['FileExtension' => []],
        $directory,
        FileExists::Rename,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Context document upload failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be saved.',
        500,
      );
    }

    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new ActionException(
        'invalid_request',
        implode(' ', $messages),
        400,
      );
    }

    return $result->getFile();
  }

  /**
   * Wraps a Symfony upload for Drupal's upload handler.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The source upload.
   *
   * @return \Drupal\file\Upload\UploadedFileInterface
   *   The Drupal upload adapter.
   */
  private function createDrupalUploadedFile(UploadedFile $upload): UploadedFileInterface {
    if (is_uploaded_file($upload->getPathname())) {
      return new FormUploadedFile($upload);
    }

    return new InputStreamUploadedFile(
      $upload->getClientOriginalName(),
      $upload->getClientOriginalName(),
      $upload->getPathname(),
      $upload->getSize(),
    );
  }

  /**
   * Creates the document media entity for a managed file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The managed file entity.
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The source upload.
   * @param array $category
   *   The resolved category storage details.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media entity.
   */
  private function createDocumentMedia(FileInterface $file, UploadedFile $upload, array $category): MediaInterface {
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $category['mediaBundle'],
      'name' => $upload->getClientOriginalName(),
      'status' => 0,
      $category['sourceField'] => [
        'target_id' => $file->id(),
        'entity' => $file,
      ],
    ]);
    $file->enforceIsNew();
    try {
      $violations = $media->get($category['sourceField'])->validate();
    }
    finally {
      $file->enforceIsNew(FALSE);
    }
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new ActionException(
        'invalid_request',
        implode(' ', $messages),
        400,
      );
    }

    $media->save();

    if (!$media instanceof MediaInterface) {
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be saved.',
        500,
      );
    }

    return $media;
  }

  /**
   * Gets the file referenced by a document media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The referenced file, if available.
   */
  private function getDocumentFile(MediaInterface $media): ?FileInterface {
    $file = $media->get(ContextDocumentStorage::SOURCE_FIELD)->entity;
    return $file instanceof FileInterface ? $file : NULL;
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
   * Builds drafting context from the editorial session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   Context with entityTypeId, bundle, and template.
   */
  private function buildContext(AiEditorialSessionInterface $session): array {
    return [
      'entityTypeId' => 'node',
      'bundle' => $session->getContentType(),
      'template' => (string) $session->get(static::TEMPLATE_FIELD)->target_id,
    ];
  }

  /**
   * Resolves the editorial context for one drafting request.
   *
   * The tone is resolved through AiEditorialContext, which stays the single
   * source of tone wording; an invalid stored tone is a 400 exactly as the
   * former router prompt injection made it. Labels are captured at request
   * time so the provenance snapshot survives later renames. Documents stay
   * empty until their backend lands.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param \Drupal\oe_ai_assistant\AiDraftingTemplateInterface|null $template
   *   The resolved drafting template, or NULL without one.
   *
   * @return \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext
   *   The immutable per-request editorial context.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the stored tone is invalid or not prompt-ready.
   */
  private function buildEditorialContext(AiEditorialSessionInterface $session, ?AiDraftingTemplateInterface $template): EditorialContext {
    $toneId = (string) $session->get(static::TONE_FIELD)->target_id;
    $tone = NULL;
    if ($toneId !== '') {
      try {
        $tone = $this->aiEditorialContext->getTone($toneId);
      }
      catch (\InvalidArgumentException $e) {
        throw new ActionException('invalid_context', $e->getMessage(), 400);
      }
    }
    return new EditorialContext(
      toneId: $tone['id'] ?? NULL,
      toneLabel: $tone['label'] ?? NULL,
      tonePrompt: $tone['prompt'] ?? NULL,
      templateId: $template?->id(),
      templateLabel: $template?->label(),
      documents: [],
    );
  }

  /**
   * Builds the system prompt with content type context and schema.
   *
   * @param string $basePrompt
   *   The initial prompt.
   * @param array<int,mixed> $context
   *   The context to add to the prompt.
   */
  private function buildSystemPrompt(string $basePrompt, array $context): string {
    $prompt = $basePrompt
      . "\n\nContent type context:\n"
      . "bundle: " . $context['bundle'] . "\n"
      . "entity_type_id: " . $context['entityTypeId'] . "\n";

    if (!empty($context['bundle'])) {
      $groups = $this->schemaProvider->groups(
        $context['entityTypeId'], $context['bundle'], $context['template']
      );
      $prompt .= "\nAvailable field groups:\n"
        . json_encode($groups, JSON_PRETTY_PRINT) . "\n";
    }

    return $prompt;
  }

}
