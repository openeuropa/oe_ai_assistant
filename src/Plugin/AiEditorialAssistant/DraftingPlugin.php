<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant;

use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\ai_neuron\Channel\SseChannel;
use Drupal\ai_neuron\Entity\NeuronMessageInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Url;
use Drupal\file\Upload\InputStreamFileWriterInterface;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Service\Drafting\ContextDocumentRepository;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentRepositoryInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Exception\DraftingTemplateException;
use Drupal\oe_ai_assistant\Exception\DraftingToneException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\RequestDraftNeuronTool;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\PreviewRendererInterface;
use Drupal\oe_ai_assistant\Response\SseResponse;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\ChatTurnEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drafting plugin: AI-powered content drafting with SSE streaming.
 *
 * The chat action streams the `editorial` Neuron agent, which owns its own
 * tools, conversation thread, and per-group drafting through the
 * `draft_group` workflow. The conversation is scoped by an editorial
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
   * Builds the run that serves one turn.
   *
   * @var \Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface
   */
  protected NeuronWorkflowManagerInterface $workflows;

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
   * The tool plugin manager, which holds the label of every tool.
   *
   * @var \Drupal\ai_neuron\Tools\NeuronToolManagerInterface
   */
  protected NeuronToolManagerInterface $toolManager;

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
    $instance->workflows = $container->get(NeuronWorkflowManagerInterface::class);
    $instance->schemaProvider = $container->get(DraftingSchemaProviderInterface::class);
    $instance->draftSaver = $container->get(DraftSaverInterface::class);
    $instance->aiEditorialContext = $container->get(AiEditorialContextInterface::class);
    $instance->contextDocumentRepository = $container->get(ContextDocumentRepository::class);
    $instance->inputStreamFileWriter = $container->get(InputStreamFileWriterInterface::class);
    $instance->draftAssembler = $container->get(DraftAssemblerInterface::class);
    $instance->previewRenderer = $container->get(PreviewRendererInterface::class);
    $instance->toolManager = $container->get(NeuronToolManagerInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getActionMap(): array {
    return parent::getActionMap() + [
      'chat' => $this->chat(...),
      'get-messages' => $this->getMessages(...),
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
    return parent::getRequestSchemas() + [
      'get-messages' => 'GetMessagesRequest',
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
      // A tool call reaches the app under its machine name alone. A card
      // built from that name would show the editor drafting_draft_content.
      // So the config carries the label every tool plugin declares, keyed
      // by that name.
      'toolLabels' => $this->toolLabels(),
    ];
  }

  /**
   * Collects the label every tool plugin declares.
   *
   * @return array
   *   The labels, keyed by the machine name of the tool.
   */
  private function toolLabels(): array {
    $labels = [];
    foreach ($this->toolManager->getDefinitions() as $id => $definition) {
      $labels[(string) $id] = (string) $definition->label;
    }

    return $labels;
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
   * Builds the editorial turn of the session and drives it to completion.
   * The run answers the editor and writes the draft the answer asks for; the
   * SSE channel built alongside it frames and writes every protocol event.
   * This action only carries that stream to the HTTP response.
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
    if (!$session instanceof ContentCreationSessionInterface) {
      throw new ActionException('invalid_request', 'The editorial session does not support drafting.', 400);
    }

    $channel = new SseChannel();
    // Building the run builds the agent of its chat nodes, which resolves the
    // session's template: its instructions() reads the schema groups. So a
    // stale stored template surfaces here, and becomes a clean 400 rather
    // than an error page in the middle of an open stream. Catching the
    // narrower subclass rather than the base \InvalidArgumentException
    // matters: the same build resolves the provider and the channel, and
    // either can throw the base class for reasons that are a site
    // misconfiguration rather than the editor's. Those must not be
    // downgraded into a 400 that echoes their raw message.
    try {
      $workflow = $this->workflows->withChannel($channel)->createWorkflow(
        plugin_id: 'editorial_turn',
        contexts: ['session' => $session],
      );
    }
    catch (DraftingTemplateException $e) {
      throw new ActionException('invalid_request', $e->getMessage(), 400);
    }
    catch (DraftingToneException $e) {
      throw new ActionException('invalid_context', $e->getMessage(), 400);
    }

    $logger = $this->logger;
    $response = new SseResponse(static function () use ($workflow, $message, $channel, $logger): void {
      try {
        // A run executes while its generator is advanced, and run() is what
        // advances it to the end. The channel wrote every event as the run
        // produced it, so nothing here reads what it answers.
        $workflow->run(ExecutionRequest::start(new ChatTurnEvent($message)));
      }
      catch (\Throwable $e) {
        // Never let an exception escape into the open SSE stream: Drupal's
        // exception handler would print an HTML error page mid-stream.
        $logger->error('Drafting chat stream failed: @message', [
          '@message' => $e->getMessage(),
          'exception' => $e,
        ]);
        $channel->send(new ProtocolEvent('error', [
          'errorText' => 'The assistant request failed. Please try again.',
        ]));
      }
    },
      200,
      ['x-vercel-ai-ui-message-stream' => 'v1'],
      // The sentinel this protocol ends with. The response writes it, because
      // a run reaches the client through a channel and the channel is a
      // transport: a hub that frames the stream itself has no body to end.
      "data: [DONE]\n\n",
    );

    return $response;
  }

  /**
   * Returns the user-visible transcript for the session.
   *
   * Reads the editor's own thread off the Neuron store: every sub-agent
   * thread a field group drafts in belongs to a different agent, so it is
   * never in here. A settled tool call is joined against the drafts thread
   * for drafting_draft_content specifically, because the call itself settles
   * with a bare {draft, version} and the artifact rehydrates from the full
   * {version, context, fields} the drafts thread carries.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return array
   *   An array with a `messages` list of {role, content} entries.
   */
  public function getMessages(Request $request): array {
    $session = $this->loadSession($this->decodeJsonBody($request));
    // assert() is a no-op outside development (zend.assertions off by
    // default), so a session of a bundle this plugin does not serve must
    // be refused here rather than reach the typed calls below as a raw
    // TypeError.
    if (!$session instanceof ContentCreationSessionInterface) {
      throw new ActionException('invalid_request', 'The editorial session does not support drafting.', 400);
    }
    $thread = $session->conversationThread($session->editorialThread());

    // Preload the authors of user turns in one query, grouped by uid, so
    // each turn can carry the author's display name for avatars and the
    // participants list.
    $authorIds = [];
    foreach ($thread as $row) {
      $uid = (int) $row->getOwnerId();
      if ($row->get('role')->value === 'user' && $uid > 0) {
        $authorIds[$uid] = $uid;
      }
    }
    $authors = $authorIds === []
      ? []
      : $this->entityTypeManager->getStorage('user')->loadMultiple($authorIds);

    // The store is append-only. A settled call keeps its own row beside its
    // result row, and a transcript that renders both shows the drafting turn
    // twice. Both rows name the same call id. These are the ids a later row
    // settled.
    $settled = [];
    foreach ($thread as $row) {
      $meta = $row->getMeta() ?? [];
      if (($meta['type'] ?? '') !== 'tool_call_result') {
        continue;
      }
      foreach ((array) ($meta['tools'] ?? []) as $tool) {
        if (is_array($tool) && isset($tool['callId'])) {
          $settled[(string) $tool['callId']] = TRUE;
        }
      }
    }

    $messages = [];
    foreach ($thread as $row) {
      // The 'created' field is a plain UNIX timestamp (the 'created' field
      // type has no computed date property, unlike 'datetime'); exposed in
      // RFC 3339 so clients can render local timestamps.
      $at = date('c', (int) $row->get('created')->value);

      // Neuron stores a tool result under the user role. The editor reads
      // the model's action instead.
      $toolCalls = $this->toolCallsOf($row, $settled, $session);
      if ($toolCalls !== []) {
        $messages[] = ['role' => 'assistant', 'at' => $at, 'toolCalls' => $toolCalls];
        continue;
      }

      // Only user and assistant turns are shown to the editor.
      $role = (string) $row->get('role')->value;
      if (!in_array($role, ['user', 'assistant'], TRUE)) {
        continue;
      }
      $content = $session->textOf((array) ($row->getContent() ?? []));
      // Skip empty turns that carry neither text nor a tool call.
      if ($content === '') {
        continue;
      }
      $item = ['role' => $role, 'content' => $content, 'at' => $at];
      $uid = (int) $row->getOwnerId();
      if ($role === 'user' && isset($authors[$uid])) {
        $item['userId'] = (string) $uid;
        $item['userName'] = (string) $authors[$uid]->getDisplayName();
      }
      $messages[] = $item;
    }

    return ['messages' => $messages];
  }

  /**
   * The tool calls of a row, as the editor's transcript renders them.
   *
   * A settled call carries its result. A run suspends on an approval-gated
   * call, which has no result yet, and the editor sees a running card. A call
   * a later row settled belongs to that row.
   *
   * @param \Drupal\ai_neuron\Entity\NeuronMessageInterface $row
   *   The stored message.
   * @param array $settled
   *   The ids of the calls the thread settled, keyed by id.
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session hosting the drafts thread.
   *
   * @return array
   *   One entry per call, shaped {function: {name}, result?}. A row that is
   *   not a tool row has none.
   */
  private function toolCallsOf(NeuronMessageInterface $row, array $settled, ContentCreationSessionInterface $session): array {
    $meta = $row->getMeta() ?? [];

    if (($meta['type'] ?? '') === 'tool_call_result') {
      return array_map(
        fn (array $result): array => [
          'function' => ['name' => $result['name']],
          'result' => $this->joinDraftToolResult($session, $result['name'], $result['result']),
        ],
        $row->getToolResults(),
      );
    }

    if (($meta['type'] ?? '') !== 'tool_call') {
      return [];
    }

    $unsettled = array_filter(
      (array) ($meta['tools'] ?? []),
      // The key sits in a text column, so check its shape too.
      static fn ($tool): bool => is_array($tool)
        && isset($tool['name'], $tool['callId'])
        && !isset($settled[(string) $tool['callId']]),
    );

    return array_map(
      static function (array $tool): array {
        $call = ['function' => ['name' => (string) $tool['name']]];

        // A call with no result draws a card that spins forever. A
        // drafting call settles in the request that asked for it, so a
        // call the transcript finds open wrote no draft. The transcript
        // hands that call a failure as its result.
        if ((string) $tool['name'] === RequestDraftNeuronTool::TOOL_ID) {
          $call['result'] = self::failedDraft('');
        }

        return $call;
      },
      array_values($unsettled),
    );
  }

  /**
   * The record the card reads for a drafting call that wrote no draft.
   *
   * @param string $error
   *   What the call reported, empty when it reported nothing.
   *
   * @return array
   *   The record.
   */
  private static function failedDraft(string $error): array {
    return [
      'status' => 'failed',
      'error' => $error === '' ? 'The draft did not finish.' : $error,
    ];
  }

  /**
   * Replaces a settled drafting_draft_content result with its full shape.
   *
   * The tool settles its call with a bare {draft, version} so a schema of any
   * size costs the conversation the same. The editor's card reads the stored
   * record instead. That record carries the groups and what happened to each
   * one.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session hosting the drafts thread.
   * @param string $toolName
   *   The name of the settled call.
   * @param array $result
   *   The call's own settled result.
   *
   * @return array
   *   The stored record for drafting_draft_content, or the failure when the
   *   call names no version. Any other tool, and a version no run stored,
   *   keep the result they came with.
   */
  private function joinDraftToolResult(ContentCreationSessionInterface $session, string $toolName, array $result): array {
    if ($toolName !== RequestDraftNeuronTool::TOOL_ID) {
      return $result;
    }

    if (!isset($result['version'])) {
      // The card reads a result with no version as a fields map, and draws
      // a draft nobody wrote. A later turn settles the open call with the
      // interruption the library reports, which arrives as content blocks.
      // So the failure here carries the text of those blocks.
      return self::failedDraft($session->textOf((array) ($result['blocks'] ?? [])));
    }

    return $session->getDraftRun((int) $result['version']) ?? $result;
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

    if (!$session instanceof ContentCreationSessionInterface) {
      throw new ActionException('invalid_request', 'The editorial session does not support drafting.', 400);
    }
    $draft = $session->getDraftContent($version);
    if ($draft === NULL || $draft['fields'] === []) {
      throw new ActionException(
        'invalid_request',
        sprintf('Draft %d does not exist in this session.', $version),
        400,
      );
    }

    // No save event is recorded; the editorial change log is an accepted,
    // documented loss until OEL-5127 answers it from session revisions.
    return $this->draftSaver->save($session, $draft['fields'], $draft['templateId'], $version);
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

    if (!$session instanceof ContentCreationSessionInterface) {
      throw new ActionException('invalid_request', 'The editorial session does not support drafting.', 400);
    }
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
    // buildSelectedPrompt.
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
