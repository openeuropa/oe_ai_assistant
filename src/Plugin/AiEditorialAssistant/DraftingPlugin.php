<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai\Response\AiStreamedResponse;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Url;
use Drupal\file\Upload\InputStreamFileWriterInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\PreviewRendererInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * Each chat turn is one run of the drafting agent: it converses until it
 * calls draft_group for every field group, each call drafts its group
 * through a sub-agent, and the draft is versioned on the transcript once
 * the set is complete.
 *
 * The conversation is scoped by an editorial session: the session hosts the
 * persisted conversation rows, and its target content type drives
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
   * The drafting schema provider.
   *
   * @var \Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface
   */
  protected DraftingSchemaProviderInterface $schemaProvider;

  /**
   * The agent plugin manager, which builds the drafting agent.
   *
   * @var \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface
   */
  protected NeuronAgentManagerInterface $agentManager;

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
   * The context document repository.
   *
   * @var \Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository
   */
  protected ContextDocumentRepository $contextDocumentRepository;

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
    $instance->schemaProvider = $container->get(DraftingSchemaProviderInterface::class);
    $instance->agentManager = $container->get(NeuronAgentManagerInterface::class);
    $instance->aiEditorialContext = $container->get(AiEditorialContextInterface::class);
    $instance->draftHistory = $container->get(DraftHistoryInterface::class);
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
      'get-approvals' => $this->getApprovals(...),
      'submit-approval' => $this->submitApproval(...),
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
      'get-approvals' => 'DraftingGetApprovalsRequest',
      'submit-approval' => 'DraftingSubmitApprovalRequest',
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
   * Streams an AI chat response via SSE.
   *
   * The turn workflow routes the message, drafts on the router's signal and
   * versions the result; this action only maps its chunks onto the stream.
   * History and every turn are scoped to the editorial session named by the
   * request.
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

    $agent = $this->buildAgent($body);

    return $this->streamRun($agent->stream(new UserMessage($message)), $agent);
  }

  /**
   * Lists the tool calls of the session waiting on the editor's decision.
   *
   * A gated call suspends the run until it is answered, and the run is durable,
   * so a reload reads the request back rather than losing it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request naming the session.
   *
   * @return array
   *   An array with an `approvals` list, each {id, name, description, reason,
   *   inputs}.
   */
  public function getApprovals(Request $request): array {
    $body = $this->decodeJsonBody($request);
    $agent = $this->buildAgent($body);

    return [
      'approvals' => array_map(
        static fn (Action $action): array => $action->jsonSerialize(),
        $agent->pendingApprovals(),
      ),
    ];
  }

  /**
   * Answers one gated tool call and streams the rest of the run.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request naming the session, the call and the decision.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The resumed run, as a UI message stream.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the call is unknown to the run, or the decision is not one of
   *   approve and reject.
   */
  public function submitApproval(Request $request): Response {
    $body = $this->decodeJsonBody($request);
    $callId = (string) ($body['callId'] ?? '');
    $decision = (string) ($body['decision'] ?? '');
    $reason = (string) ($body['reason'] ?? '');

    if ($decision !== 'approve' && $decision !== 'reject') {
      throw new ActionException('invalid_request', 'A decision must be approve or reject.', 400);
    }

    $agent = $this->buildAgent($body);

    $pending = array_map(static fn (Action $action): string => $action->id, $agent->pendingApprovals());
    if (!in_array($callId, $pending, TRUE)) {
      throw new ActionException('invalid_request', 'No such call is waiting for a decision.', 400);
    }

    // A rejection carries the editor's words to the model, so it can say what
    // was turned down rather than retrying the same call.
    $answer = $decision === 'reject' && $reason !== '' ? ['reject', $reason] : $decision;

    return $this->streamRun($agent->submitApprovalDecisions([$callId => $answer])->events(), $agent);
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
    $this->conversation->deleteFor($session);
    return ['status' => 'ok'];
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

    $draft = $this->draftHistory->getDraftContent($session, $version);
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

    // Validate before any write, with the same exception buildSelectedPrompt
    // raises for a tone that is not prompt-ready.
    try {
      $this->aiEditorialContext->getTone($toneId);
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
   * Builds the drafting agent for the session the request names.
   *
   * The agent and its tools are plugins, so a manager builds them and no
   * caller can hand them anything: the session travels as the configuration
   * the manager passes on, and the agent reads the rest off it.
   *
   * @param array $body
   *   The decoded request body, which names the session.
   *
   * @return \NeuronAI\Agent\AgentInterface
   *   The agent.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   When the session is unknown, or its stored template or tone is not one
   *   the bundle can draft with.
   */
  private function buildAgent(array $body): AgentInterface {
    $session = $this->loadSession($body);

    try {
      return $this->agentManager->createAgent('drafting', ['session' => $session]);
    }
    catch (\InvalidArgumentException $e) {
      throw new ActionException('invalid_request', $e->getMessage(), 400);
    }
  }

  /**
   * Streams an agent run as UI message stream events.
   *
   * Neuron yields the protocol events and frames them; the response, the
   * flush and what is sent after the run are the host's. A failure mid-stream
   * degrades into an error event, since an exception would print an HTML page
   * into the stream.
   */
  private function streamRun(\Generator $run, AgentInterface $agent): Response {
    $response = new AiStreamedResponse(NULL, 200, (new VercelAIAdapter())->getHeaders());
    $response->setCallback(function () use ($run, $agent): void {
      set_time_limit(0);
      $emit = static function (ProtocolEvent $event): void {
        echo SSEEncoder::frame($event);
        flush();
      };

      // The event closing the stream is held back: the app stops reading the
      // message once it arrives, and a suspended run has a question to ask
      // first.
      $terminal = NULL;
      try {
        foreach ($run as $protocolEvent) {
          if (in_array($protocolEvent->type, ['finish', 'error'], TRUE)) {
            $terminal = $protocolEvent;
            continue;
          }
          $emit($protocolEvent);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Drafting turn failed: @message', ['@message' => $e->getMessage()]);
        $terminal = new ProtocolEvent('error', ['errorText' => 'The assistant request failed. Please try again.']);
      }

      // A gated tool call suspends the run with no part of its own in the
      // stream, so the request is sent here rather than left for the app to go
      // and ask for.
      $approvals = array_map(
        static fn (Action $action): array => $action->jsonSerialize(),
        $agent->pendingApprovals(),
      );
      if ($approvals !== []) {
        $emit(new ProtocolEvent('data-approval-request', ['data' => ['approvals' => $approvals]]));
      }
      if ($terminal instanceof ProtocolEvent) {
        $emit($terminal);
      }

      // Neuron stopped sending the sentinel, since a protocol does not get to
      // decide how a transport ends. The decoder the app runs still reads a
      // stream without it as truncated, so the transport sends it.
      echo "data: [DONE]\n\n";
      flush();
    });

    return $response;
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
