<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Channel\NeuronChannelManagerInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Url;
use Drupal\file\Upload\InputStreamFileWriterInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\PreviewRendererInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * The chat action streams the `drafting` Neuron agent, which owns its own
 * tools, conversation thread, and per-group drafting through the
 * `field_group` workflow. The conversation is scoped by an editorial
 * session, which the agent reads as plugin context; any user with access
 * to the session sees the same conversation.
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
   * The Neuron agent plugin manager.
   *
   * @var \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface
   */
  protected NeuronAgentManagerInterface $agentManager;

  /**
   * The Neuron channel plugin manager.
   *
   * @var \Drupal\ai_neuron\Channel\NeuronChannelManagerInterface
   */
  protected NeuronChannelManagerInterface $channelManager;

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
   * The editorial tone context service.
   *
   * @var \Drupal\oe_ai_assistant\Service\AiEditorialContextInterface
   */
  protected AiEditorialContextInterface $aiEditorialContext;

  /**
   * The context document repository.
   *
   * @var \Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface
   */
  protected DocumentRepositoryInterface $contextDocumentRepository;

  /**
   * The input stream file writer, which stages raw upload bodies.
   *
   * @var \Drupal\file\Upload\InputStreamFileWriterInterface
   */
  protected InputStreamFileWriterInterface $inputStreamFileWriter;

  /**
   * The draft assembler.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftAssemblerInterface
   */
  protected DraftAssemblerInterface $draftAssembler;

  /**
   * The preview renderer.
   *
   * @var \Drupal\oe_ai_assistant\Service\PreviewRendererInterface
   */
  protected PreviewRendererInterface $previewRenderer;

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
    $instance->agentManager = $container->get(NeuronAgentManagerInterface::class);
    $instance->channelManager = $container->get(NeuronChannelManagerInterface::class);
    $instance->schemaProvider = $container->get(DraftingSchemaProviderInterface::class);
    $instance->draftSaver = $container->get(DraftSaverInterface::class);
    $instance->aiEditorialContext = $container->get(AiEditorialContextInterface::class);
    $instance->contextDocumentRepository = $container->get(ContextDocumentRepository::class);
    $instance->inputStreamFileWriter = $container->get(InputStreamFileWriterInterface::class);
    $instance->draftAssembler = $container->get(DraftAssemblerInterface::class);
    $instance->previewRenderer = $container->get(PreviewRendererInterface::class);
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
      'extract-document' => $this->extractDocument(...),
      'preview' => $this->preview(...),
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
      'extract-document' => 'DraftingExtractDocumentRequest',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The add-document body is the raw file, so its parameters are query
   * string values.
   */
  public function getQueryActions(): array {
    return ['add-document'];
  }

  /**
   * {@inheritdoc}
   *
   * Provides the drafting scope (entity type and bundle) and the composer
   * panels. Each panel is gated by an 'enabled' flag so the host controls
   * which tabs appear. Tone options come from the tone vocabulary; template
   * options come from the enabled drafting templates for the bundle; the
   * document list is fetched by the app through the list-documents action,
   * and the accepted file extensions come from the document source field.
   */
  public function getAppConfig(AiEditorialSessionInterface $session, RefinableCacheableDependencyInterface $cacheability): array {
    $context = $this->buildContext($session);
    // The configuration embeds the drafting template list, so the page must
    // be invalidated whenever a template is added, edited or deleted. The
    // list cache tag covers all three operations for config entities.
    $cacheability->addCacheTags(['config:ai_drafting_template_list']);
    // The accepted extensions come from the document source field, so a
    // field settings change must invalidate the page as well.
    $cacheability->addCacheTags(['config:field_config_list']);

    return [
      'entityTypeId' => $context['entityTypeId'],
      'bundle' => $context['bundle'],
      'tone' => [
        'enabled' => TRUE,
        'options' => $this->serializeToneOptions($this->aiEditorialContext->getAvailableTones()),
        // The tone already saved on the session, so the app can rehydrate
        // the selector on load.
        'selected' => (string) $session->get(static::TONE_FIELD)->target_id,
      ],
      'templates' => [
        'enabled' => TRUE,
        'options' => $this->schemaProvider->availableTemplates($context['bundle']),
        'selected' => (string) $session->get(static::TEMPLATE_FIELD)->target_id,
      ],
      'documents' => [
        // The app fetches the document list through the list-documents
        // action after boot; the bootstrap only carries the gate and the
        // extensions the upload control offers.
        'enabled' => TRUE,
        'extensions' => $this->contextDocumentRepository->getAllowedExtensions(),
      ],
      // Live preview iframe URL template. The app substitutes the
      // {sessionId} and {versionId} placeholders before loading the
      // draft preview endpoint in the artifact pane iframe.
      'preview' => [
        'url' => Url::fromRoute('oe_ai_assistant.drafting_preview')->toString() . '?sessionId={sessionId}&version={versionId}',
      ],
    ];
  }

  /**
   * Serializes internal prompt-ready tone options for frontend bootstrap.
   *
   * @param array<int, array{id: string, label: string, description: string, oe_ai_prompt: string}> $options
   *   The prompt-ready service options.
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   Frontend-safe tone options.
   */
  private function serializeToneOptions(array $options): array {
    return array_map(
      static fn (array $option): array => [
        'id' => $option['id'],
        'label' => $option['label'],
        'description' => $option['description'],
      ],
      $options,
    );
  }

  /**
   * Streams an AI chat response via SSE.
   *
   * Builds the drafting Neuron agent for the session and frames every
   * protocol event its stream yields with SSEEncoder, ending with the
   * [DONE] sentinel the frontend's decoder waits for. The agent owns its
   * own conversation, tools, and per-turn drafting; this action only
   * carries its stream to the HTTP response.
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

    // SSEEncoder::frame() returns an already-framed "data: {...}\n\n"
    // string. Symfony's ServerEvent/EventStreamResponse frame raw data
    // themselves, so handing them a pre-framed string would double-wrap it;
    // a plain StreamedResponse with the SSE headers set by hand, and a
    // writer the channel shares, is what writes every frame to the wire
    // untouched. The channel carries per-group progress and the drafted
    // fields: a tool cannot stream into the agent's own generator, so
    // DraftContentNeuronTool reports through it instead.
    $write = static function (string $frame): void {
      echo $frame;
      flush();
    };
    $channel = $this->channelManager->createChannel('sse', ['writer' => $write]);
    $agent = $this->agentManager->createAgent('drafting', ['session' => $session], [], $channel);

    $response = new StreamedResponse(function () use ($agent, $message, $write): void {
      set_time_limit(0);
      ini_set('zlib.output_compression', '0');
      ini_set('implicit_flush', '1');
      if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
      }
      foreach ($agent->stream(new UserMessage($message)) as $event) {
        $write(SSEEncoder::frame($event));
      }
      $write("data: [DONE]\n\n");
    });
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('X-Accel-Buffering', 'no');
    $response->headers->set('x-vercel-ai-ui-message-stream', 'v1');

    return $response;
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
    $session->deleteConversation();
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

    assert($session instanceof ContentCreationSessionInterface);
    $draft = $session->getDraftContent($version);
    if ($draft === NULL || $draft['fields'] === []) {
      throw new ActionException(
        'invalid_request',
        sprintf('Draft %d does not exist in this session.', $version),
        400,
      );
    }

    $result = $this->draftSaver->save($session, $draft['fields'], $draft['templateId'], $version);

    $this->messageRecorder->recordEvent(
      $session,
      sprintf('Draft %d saved as unpublished revision', $version),
      ['type' => 'save', 'version' => $version, 'nodeId' => $result['nodeId']],
      (int) $this->currentUser->id(),
    );

    return $result;
  }

  /**
   * Renders a themed HTML preview of a stored draft version.
   *
   * Builds the unsaved node the same way save() would (bundle validation,
   * create-permission check, template-defaults merge via DraftAssembler),
   * but never persists it: the built node is handed straight to
   * PreviewRenderer and discarded after the response is built.
   *
   * Unlike the other actions this one is addressed with GET, so the
   * app's preview iframe can load it through its src attribute. The
   * sessionId and version parameters therefore come from the query
   * string instead of a JSON body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The preview request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A complete HTML document response.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   On a missing/invalid version, an unresolvable template, an invalid
   *   bundle, missing permission, or a build/render failure.
   */
  public function preview(Request $request): Response {
    $session = $this->loadSession([
      'sessionId' => (string) $request->query->get('sessionId', ''),
    ]);

    $version = (int) $request->query->get('version', 0);
    if ($version <= 0) {
      throw new ActionException('invalid_request', 'A positive version is required.', 400);
    }

    assert($session instanceof ContentCreationSessionInterface);
    $draft = $session->getDraftContent($version);
    if ($draft === NULL) {
      throw new ActionException(
        'invalid_request',
        sprintf('Draft version %d was not found.', $version),
        404,
      );
    }

    $bundle = $session->getContentType();
    $node = $this->draftAssembler->assemble($bundle, $draft['fields'], $draft['templateId']);

    return $this->previewRenderer->render($node);
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
   * Adds an uploaded document to the session document references.
   *
   * The file bytes form the whole request body and are staged to a
   * temporary file the same way core's JSON:API file upload does. The
   * session, category and filename travel in the query string, validated
   * against the request schema before this action runs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming raw upload request.
   *
   * @return array<string, array<string, string|array<string, string>>>
   *   The serialized document item.
   */
  public function addDocument(Request $request): array {
    $params = $request->query->all();
    $repository = $this->resolveDocumentRepository($params['category'] ?? '');
    $session = $this->loadSession($params);

    // Drop any path component, as core does for Content-Disposition names.
    $filename = basename($params['filename'] ?? '');
    try {
      $path = $this->inputStreamFileWriter->writeStreamToFile();
    }
    catch (FileException $e) {
      // The body could not be staged, for example on a dropped connection.
      // Report it in the same shape as the other upload failures.
      $this->logger->error('Document upload could not be staged: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new ActionException(
        'upload_failed',
        'The uploaded document could not be read.',
        500,
      );
    }
    $upload = new InputStreamUploadedFile($filename, $filename, $path, @filesize($path));

    return ['document' => $repository->add($session, $upload)];
  }

  /**
   * Lists the documents of the session.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array<string, array<int, array<string, string|array<string, string>>>>
   *   The serialized document items.
   */
  public function listDocuments(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $repository = $this->resolveDocumentRepository($body['category'] ?? '');
    $session = $this->loadSession($body);

    return ['documents' => $repository->list($session)];
  }

  /**
   * Removes a document of the session and deletes its entities.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array<string, string>
   *   A confirmation response.
   */
  public function removeDocument(Request $request): array {
    [$repository, $session, $documentId] = $this->resolveDocumentRequest($request);
    $repository->remove($session, $documentId);

    return ['status' => 'ok'];
  }

  /**
   * Runs text extraction and summarisation on a referenced document.
   *
   * Fired by the app after a successful upload; also the retry entry point.
   * Processing is synchronous; the response carries the resulting state.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array<string, string>
   *   The document state.
   */
  public function extractDocument(Request $request): array {
    [$repository, $session, $documentId] = $this->resolveDocumentRequest($request);

    return ['status' => $repository->extract($session, $documentId)];
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
   * Resolves the repository, session and document id of a document request.
   *
   * Shared by the actions that target one existing document.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming JSON request.
   *
   * @return array
   *   The document repository, the session and the document id.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the document id is missing.
   */
  private function resolveDocumentRequest(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $repository = $this->resolveDocumentRepository($body['category'] ?? '');
    $session = $this->loadSession($body);
    $documentId = (string) ($body['documentId'] ?? '');

    if ($documentId === '') {
      throw new ActionException(
        'invalid_request',
        'A documentId is required.',
        400,
      );
    }

    return [$repository, $session, $documentId];
  }

  /**
   * Resolves a document category into the repository that serves it.
   *
   * @param string $category
   *   The request category.
   *
   * @return \Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface
   *   The document repository for the category.
   */
  private function resolveDocumentRepository(string $category): DocumentRepositoryInterface {
    if ($category === ContextDocumentRepository::CATEGORY) {
      return $this->contextDocumentRepository;
    }

    throw new ActionException(
      'invalid_request',
      sprintf('Unsupported document category "%s".', $category),
      400,
    );
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

}
