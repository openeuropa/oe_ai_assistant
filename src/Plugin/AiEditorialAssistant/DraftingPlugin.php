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
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Exception\DocumentSummaryExtractionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DocumentSerializerInterface;
use Drupal\oe_ai_assistant\Service\DraftingOrchestratorInterface;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\SupportingDocumentPromptBuilderInterface;
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
   * The document category used for private drafting context files.
   */
  protected const string CONTEXT_DOCUMENT_CATEGORY = 'context';

  /**
   * The session field that references private context documents.
   */
  protected const string CONTEXT_DOCUMENT_SESSION_FIELD = 'context_documents';

  /**
   * The media bundle used for private context documents.
   */
  protected const string CONTEXT_DOCUMENT_MEDIA_BUNDLE = 'ai_context_document';

  /**
   * The media source field that stores the uploaded context file.
   */
  protected const string CONTEXT_DOCUMENT_SOURCE_FIELD = 'field_media_context_document';

  /**
   * The private directory used for uploaded context documents.
   */
  protected const string CONTEXT_DOCUMENT_UPLOAD_DIRECTORY = 'private://ai-context-documents';

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
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $fileRepository;

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
   * The supporting-document prompt builder.
   *
   * @var \Drupal\oe_ai_assistant\Service\SupportingDocumentPromptBuilderInterface
   */
  protected SupportingDocumentPromptBuilderInterface $supportingDocumentPromptBuilder;

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
    $instance->fileRepository = $container->get('file.repository');
    $instance->fileSystem = $container->get('file_system');
    $instance->documentSerializer = $container->get(DocumentSerializerInterface::class);
    $instance->supportingDocumentPromptBuilder = $container->get(SupportingDocumentPromptBuilderInterface::class);
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
            $context['template'],
            $context['supportingDocumentSummaries'],
          );
          // Emit the draft_content tool call with its result so the card
          // appears live, matching what a reload rehydrates.
          $stream->toolCall('draft_content', [], $drafted);
          // Record the drafted fields as the result of the draft_content call
          // so the transcript keeps a trace that can repopulate the artifact.
          if ($lastAssistant !== NULL) {
            $this->attachDraftResult($lastAssistant, $drafted);
          }
          // Stream and record a confirmation so it survives a reload.
          if ($drafted) {
            $confirmation = sprintf(
              'Draft generated with %d fields. Review the content on the right.',
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

    try {
      $this->aiEditorialContext->buildSelectedPrompt($toneId);
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException(
        'invalid_context',
        $e->getMessage(),
        400,
      );
    }

    $session = $this->loadSession($body);
    $session->set(static::TONE_FIELD, $toneId);
    $session->save();

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
    return ['status' => 'ok'];
  }

  /**
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
    $this->validateUploadedDocumentExtension($upload, $category);

    $managedFile = $this->saveUploadedDocument($upload);
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
    $category = $this->resolveDocumentCategory((string) ($body['category'] ?? ''));
    $session = $this->loadSession($body);
    $documentId = (string) ($body['documentId'] ?? '');

    if ($documentId === '') {
      throw new ActionException(
        'invalid_request',
        'A documentId is required.',
        400,
      );
    }

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
   * The drafted fields are the output of the draft_content tool, produced by
   * the orchestrator after the loop returns. Storing them on the tool call
   * lets the transcript render a clickable trace that repopulates the artifact.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The assistant turn that triggered drafting.
   * @param array $drafted
   *   The consolidated drafted field values.
   */
  private function attachDraftResult(AiConversationMessageInterface $message, array $drafted): void {
    $toolCalls = $message->getToolCalls();
    $found = FALSE;
    foreach ($toolCalls as &$call) {
      if (($call['function']['name'] ?? '') === 'draft_content') {
        $call['result'] = $drafted;
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
        'result' => $drafted,
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
   * Validates the uploaded document extension against the media field config.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   * @param array $category
   *   The resolved category storage details.
   */
  private function validateUploadedDocumentExtension(UploadedFile $upload, array $category): void {
    $fieldConfig = $this->entityTypeManager->getStorage('field_config')
      ->load('media.' . $category['mediaBundle'] . '.' . $category['sourceField']);
    $extensions = preg_split(
      '/\s+/',
      trim((string) $fieldConfig?->getSetting('file_extensions')),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];
    $extension = strtolower($upload->getClientOriginalExtension());

    if (!in_array($extension, $extensions, TRUE)) {
      throw new ActionException(
        'invalid_request',
        sprintf('The uploaded document extension "%s" is not allowed.', $extension),
        400,
      );
    }
  }

  /**
   * Saves an uploaded document as a managed private file.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The uploaded file.
   *
   * @return \Drupal\file\FileInterface
   *   The managed file entity.
   */
  private function saveUploadedDocument(UploadedFile $upload): FileInterface {
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

    $filename = $this->fileSystem->basename($upload->getClientOriginalName());
    $destination = $directory . '/' . $filename;
    $data = file_get_contents($upload->getPathname());
    if ($data === FALSE) {
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be read.',
        500,
      );
    }

    try {
      return $this->fileRepository->writeData(
        $data,
        $destination,
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
      ],
    ]);
    try {
      $media->save();
    }
    catch (DocumentSummaryExtractionException $e) {
      if ($media instanceof MediaInterface && !$media->isNew()) {
        $media->delete();
      }
      throw new ActionException(
        'summary_extraction_failed',
        'The uploaded document could not be summarised.',
        500,
      );
    }

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
   *   Context with entityTypeId, bundle, toneId, template, and supporting
   *   document summaries.
   */
  private function buildContext(AiEditorialSessionInterface $session): array {
    return [
      'entityTypeId' => 'node',
      'bundle' => $session->getContentType(),
      'toneId' => (string) $session->get(static::TONE_FIELD)->target_id,
      'template' => (string) $session->get(static::TEMPLATE_FIELD)->target_id,
      'supportingDocumentSummaries' => $this->collectSupportingDocumentSummaries($session),
    ];
  }

  /**
   * Collects current supporting-document summaries from the session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array<int, array{label: string, summary: string}>
   *   Labelled summaries for prompt context.
   */
  private function collectSupportingDocumentSummaries(AiEditorialSessionInterface $session): array {
    if (!$session->hasField(ContextDocumentStorage::SESSION_FIELD)) {
      return [];
    }

    $summaries = [];
    foreach ($session->get(ContextDocumentStorage::SESSION_FIELD)->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface
        || $media->bundle() !== ContextDocumentStorage::MEDIA_BUNDLE
        || !$media->hasField(ContextDocumentStorage::SUMMARY_FIELD)
        || $media->get(ContextDocumentStorage::SUMMARY_FIELD)->isEmpty()
      ) {
        continue;
      }

      $summary = trim((string) $media->get(ContextDocumentStorage::SUMMARY_FIELD)->value);
      if ($summary === '') {
        continue;
      }

      $summaries[] = [
        'label' => $this->documentContextLabel($media),
        'summary' => $summary,
      ];
    }

    return $summaries;
  }

  /**
   * Builds a readable document label for prompt context.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The document media entity.
   *
   * @return string
   *   The media label or source filename.
   */
  private function documentContextLabel(MediaInterface $media): string {
    $label = trim((string) $media->label());
    if ($label !== '') {
      return $label;
    }

    return $this->getDocumentFile($media)?->getFilename() ?: 'Supporting document';
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
      $groups = $this->schemaProvider->groups(
        $context['entityTypeId'], $context['bundle'], $context['template']
      );
      $prompt .= "\nAvailable field groups:\n"
        . json_encode($groups, JSON_PRETTY_PRINT) . "\n";
    }

    if ($context['toneId'] !== '') {
      try {
        $prompt .= "\nEditorial context:\n"
          . $this->aiEditorialContext->buildSelectedPrompt($context['toneId'])
          . "\n";
      }
      catch (\InvalidArgumentException $e) {
        throw new ActionException(
          'invalid_context',
          $e->getMessage(),
          400,
        );
      }
    }

    if (!empty($context['supportingDocumentSummaries'])) {
      $section = $this->supportingDocumentPromptBuilder->buildSection($context['supportingDocumentSummaries']);
      if ($section !== '') {
        $prompt .= "\n" . $section . "\n";
      }
    }

    return $prompt;
  }

}
