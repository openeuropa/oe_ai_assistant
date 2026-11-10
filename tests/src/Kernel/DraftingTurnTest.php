<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\ChatTurnEvent;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProvider;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives one whole turn of an editorial session.
 *
 * The turn runs the workflow the chat endpoint runs, with the same start
 * event, so this covers the agent, the deferred call, the node that drafts
 * and the group drafter together. The endpoint adds the request, the channel
 * and the response around it.
 *
 * @group oe_ai_assistant
 */
class DraftingTurnTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'entity_reference_revisions',
      'paragraphs',
      'link',
      'inline_entity_form',
      'oe_ai_assistant_test',
    ]);
    $this->installEntitySchema('paragraph');
    $this->installConfig(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_structured_response' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();

  }

  /**
   * A turn drafts every group in order, versions the draft and stores it.
   *
   * Without a template the latest one for oe_news applies, which splits into
   * main_fields (title, field_teaser) and field_content_paragraphs. So one
   * deferred call of the drafting tool runs two group drafters.
   */
  public function testTurnDraftsEveryGroupAndVersionsTheDraft(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();

    $state = $this->turn($session, 'Please draft it, go ahead.');

    $this->assertSame('Draft 1 is ready.', $state->get('answer'));

    // One call drafted the whole set, and its result names the draft without
    // carrying any of its content back into the conversation.
    $results = $session->conversationToolResults();
    $this->assertCount(1, $results);
    $this->assertSame('drafting_draft_content', $results[0]['name']);
    $this->assertSame(['draft' => 'Draft 1', 'version' => 1], $results[0]['result']);

    // The draft holds what the two groups wrote, assembled in code.
    $drafts = $session->listDrafts();
    $this->assertSame(['Draft 1'], array_column($drafts, 'name'));
    $this->assertSame('news_with_paragraphs', $drafts[0]['context']['template']['id']);
    $this->assertSame([
      'title' => [['value' => 'T']],
      'field_teaser' => [['value' => 'S']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $session->getDraftContent(1)['fields']);

    // Each group ran in its own thread, named after the run that wrote it.
    $threads = $session->conversationThreads();
    $paragraphThread = $this->thread($session, 1, 'field_content_paragraphs');
    $this->thread($session, 1, 'main_fields');

    // The paragraphs drafter reads what the main fields already say, so the
    // tool drafts the groups in order rather than in parallel.
    $task = (string) ($threads[$paragraphThread][0]->getContent()[0]['content'] ?? '');
    $this->assertStringContainsString('Main fields already generated', $task);
    $this->assertStringContainsString('"title":[{"value":"T"}]', $task);
  }

  /**
   * A drafter reads what the editor and the assistant said before the draft.
   *
   * The task is the only thing a drafter reads, and the drafts stay out of
   * it. So an earlier turn reaches the drafter as a line of the conversation,
   * and the fields of an earlier draft reach it not at all.
   */
  public function testDrafterReadsTheConversationWithoutTheDrafts(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue(new MockResponse(text: 'Tell me when you are ready.'));
    $this->turn($session, 'We are writing about the floods in Valencia.');

    $this->enqueueTurn();
    $this->turn($session, 'Draft it now.');

    MockAiProvider::reset();
    $this->enqueueTurn('Draft 2 is ready.');
    $this->turn($session, 'Draft another one.');

    $threads = $session->conversationThreads();
    $task = (string) ($threads[$this->thread($session, 2, 'main_fields')][0]->getContent()[0]['content'] ?? '');

    $this->assertStringContainsString('Editor: We are writing about the floods in Valencia.', $task);
    $this->assertStringContainsString('Assistant: Tell me when you are ready.', $task);
    $this->assertStringContainsString('Assistant: Draft 1 is ready.', $task);
    $this->assertStringContainsString('Editor: Draft another one.', $task);

    // The conversation names the drafts it produced and carries none of them.
    $this->assertStringNotContainsString('field_teaser', $task);
    $this->assertStringNotContainsString('<p>B</p>', $task);
  }

  /**
   * A session owns its drafts, and deleting its conversation deletes them.
   *
   * The run writes the draft through a store bound to the session. The row
   * therefore carries the session reference, and the version a reader finds
   * it by.
   */
  public function testDraftsThreadBelongsToTheSession(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    $rows = $session->conversationThread($session->draftsThread());
    $this->assertCount(1, $rows);
    $this->assertSame('editorial_session', $rows[0]->bundle());
    $this->assertSame('1', $rows[0]->get('oe_draft_version')->value);

    // One query finds the record by its version.
    $this->assertSame(1, $session->getDraftRun(1)['version']);
    $this->assertNull($session->getDraftRun(2));

    $session->deleteConversation();

    $this->assertSame([], $session->conversationThread($session->draftsThread()));
    $this->assertSame([], $session->listDrafts());
  }

  /**
   * A second turn numbers its draft after the first.
   *
   * The version comes from the drafts stored for the session, which is what
   * the editor and the model both name a draft by.
   */
  public function testSecondTurnVersionsTheNextDraft(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    $this->enqueueTurn('Draft 2 is ready.');
    $this->turn($session, 'Draft it again.');

    $this->assertSame(['Draft 1', 'Draft 2'], array_column($session->listDrafts(), 'name'));
    $this->assertSame([1, 2], array_column($session->listDrafts(), 'version'));

    // The second run holds its own conversation, so a drafter never replays
    // the answer it gave for the draft before.
    $this->thread($session, 2, 'main_fields');
  }

  /**
   * A drafter that misses the schema answers again, and the run says so.
   */
  public function testGroupThatMissesItsSchemaIsAskedAgain(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    // The main_fields drafter leaves out field_teaser, which the group schema
    // requires, and answers the whole group on the second attempt.
    MockAiProvider::enqueue(new MockResponse(text: '{"title": [{"value": "T"}]}'));
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is ready.'));

    $this->turn($session, 'Please draft it, go ahead.');

    // The correction turn is in the drafter's own conversation: the answer
    // that missed the schema, the violations, and the answer that matched.
    $rows = $session->conversationThreads()[$this->thread($session, 1, 'main_fields')];
    $this->assertCount(4, $rows);
    $this->assertStringContainsString(
      'does not match the schema',
      (string) ($rows[2]->getContent()[0]['content'] ?? ''),
    );

    // The draft still holds both groups, so a retry costs the editor nothing.
    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'));
    $this->assertArrayHasKey('field_content_paragraphs', $session->getDraftContent(1)['fields']);
  }

  /**
   * A group that never matches its schema fails the run and stores no draft.
   */
  public function testGroupThatNeverMatchesFailsTheRun(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    // One attempt and five corrections, none of them the group's own fields.
    for ($i = 0; $i < 6; $i++) {
      MockAiProvider::enqueue(new MockResponse(text: '{"nope": []}'));
    }
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields could not be drafted.'));

    $this->turn($session, 'Please draft it, go ahead.');

    $results = $session->conversationToolResults();
    $this->assertSame('main_fields', $results[0]['result']['group']);
    $this->assertStringContainsString('does not match its schema', $results[0]['result']['error']);

    $this->assertSame([], $session->listDrafts(), 'An incomplete set produces no draft.');
  }

  /**
   * A provider failure tells the editor nothing of what the site saw.
   */
  public function testProviderFailureIsLoggedAndReportedPlainly(): void {
    $session = $this->createSession($this->createUser());
    $records = [];
    $this->container->get('logger.factory')->addLogger(new class ($records) extends AbstractLogger {

      /**
       * @param array $records
       *   The records, by reference, so the test reads what the tool logged.
       */
      public function __construct(
        private array &$records,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        // Only the placeholders of the message, because a context also
        // carries what no substitution can take, such as an exception.
        $placeholders = array_filter(
          $context,
          static fn (mixed $value, string $key): bool => str_starts_with($key, '@') && is_scalar($value),
          ARRAY_FILTER_USE_BOTH,
        );
        $this->records[] = strtr((string) $message, $placeholders);
      }

    });

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(error: new \RuntimeException('Gateway said 500: host db-77 refused')));
    MockAiProvider::enqueue(new MockResponse(text: 'The draft could not be generated.'));

    $this->turn($session, 'Please draft it, go ahead.');

    $results = $session->conversationToolResults();
    $this->assertSame([
      'error' => 'The group could not be drafted.',
      'group' => 'main_fields',
      'version' => 1,
    ], $results[0]['result']);
    $this->assertSame([], $session->listDrafts());

    // What the site saw is in the log, with the run that saw it.
    $logged = implode("\n", $records);
    $this->assertStringContainsString('host db-77 refused', $logged);
    $this->assertStringContainsString('main_fields', $logged);
    $this->assertMatchesRegularExpression('@draft_1\.[A-Za-z0-9_-]{6}@', $logged);
  }

  /**
   * The drafters read the message that asked for the draft.
   *
   * The store closes a turn on the answer that ends it, and this call comes
   * before that answer, so the turn that asked is still open while the
   * drafters run.
   */
  public function testDraftersReadTheTurnThatAskedForTheDraft(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();

    $this->turn($session, 'Write about the 2026 sett counts in Flanders.');

    $rows = $session->conversationThreads()[$this->thread($session, 1, 'main_fields')];
    $task = (string) ($rows[0]->getContent()[0]['content'] ?? '');
    $this->assertStringContainsString('2026 sett counts in Flanders', $task);
  }

  /**
   * Two calls in one reply write two drafts.
   */
  public function testTwoCallsInOneReplyWriteTwoDrafts(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
        [
          'id' => 'call_2',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: 'Drafts 1 and 2 are ready.'));

    $this->turn($session, 'Draft two of them.');

    $this->assertSame(['Draft 1', 'Draft 2'], array_column($session->listDrafts(), 'name'));

    // Each call answers with the draft it asked for, and each draft runs
    // the drafters of its own groups.
    $results = $session->conversationToolResults();
    $this->assertCount(2, $results);
    $this->assertSame(['draft' => 'Draft 1', 'version' => 1], $results[0]['result']);
    $this->assertSame(['draft' => 'Draft 2', 'version' => 2], $results[1]['result']);
    $this->assertCount(2, preg_grep('@\.group_main_fields$@', array_keys($session->conversationThreads())));
  }

  /**
   * A content type with no field group produces no draft.
   */
  public function testContentTypeWithNoFieldGroupProducesNoDraft(): void {
    $session = $this->createSession($this->createUser());
    $schema = $this->createMock(DraftingSchemaProviderInterface::class);
    $schema->method('groups')->willReturn([]);
    $schema->method('resolveTemplate')->willReturn(NULL);
    $this->container->set(DraftingSchemaProvider::class, $schema);

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(text: 'This content type cannot be drafted.'));

    $this->turn($session, 'Please draft it, go ahead.');

    $this->assertSame([], $session->listDrafts());
    $this->assertSame([
      'error' => 'The draft could not be written.',
      'group' => '',
      'version' => 1,
    ], $session->conversationToolResults()[0]['result']);
  }

  /**
   * The validator refuses a paragraph item shaped like a text field.
   *
   * A paragraph branch of the schema declares none of its fields as required,
   * so an object of any other shape matches it unless the branch forbids a
   * property it does not declare. A model that answers a text value for the
   * paragraph field would otherwise write a draft the editor cannot save.
   */
  public function testTextShapeInParagraphFieldIsRefused(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    // The shape of a text field, in the field that holds paragraphs.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_content_paragraphs": [{"value": "<p>B</p>"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is ready.'));

    $this->turn($session, 'Please draft it, go ahead.');

    // The run asked the group again, and the draft holds the paragraph of
    // the second answer.
    $rows = $session->conversationThreads()[$this->thread($session, 1, 'field_content_paragraphs')];
    $this->assertCount(4, $rows);
    $this->assertStringContainsString(
      'does not match the schema',
      (string) ($rows[2]->getContent()[0]['content'] ?? ''),
    );
    $this->assertSame(
      [['field_text_body' => [['value' => '<p>B</p>']], 'type' => [['target_id' => 'text_block']]]],
      $session->getDraftContent(1)['fields']['field_content_paragraphs'],
    );
  }

  /**
   * A run that follows a failed one takes the next version and its own threads.
   */
  public function testRunAfterFailedRunDraftsInNewThreads(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    for ($i = 0; $i < 6; $i++) {
      MockAiProvider::enqueue(new MockResponse(text: '{"nope": []}'));
    }
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields could not be drafted.'));
    $this->turn($session, 'Please draft it, go ahead.');

    $this->assertSame([], $session->listDrafts());
    $failed = array_keys($session->conversationThreads());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Try again.');

    // The failed run records what stopped it under the version it took, so
    // the editor reads one card per attempt and this run takes the next one.
    $this->assertSame(['Draft 2'], array_column($session->listDrafts(), 'name'));

    $threads = array_keys($session->conversationThreads());
    $this->assertNotEmpty(array_diff($threads, $failed), 'The second run drafts in threads of its own.');
    $this->assertCount(
      1,
      preg_grep('@\.draft_1\.[A-Za-z0-9_-]{6}\.group_main_fields$@', $threads),
      'The failed run holds the conversation of its own version.',
    );
    $this->assertCount(
      1,
      preg_grep('@\.draft_2\.[A-Za-z0-9_-]{6}\.group_main_fields$@', $threads),
      'The run that followed it holds a conversation of its own.',
    );
  }

  /**
   * A turn whose process died while drafting does not block the next one.
   *
   * A run left suspended makes every later turn of the session fail. The
   * next turn drops that run and answers the open call, so the conversation
   * continues.
   */
  public function testTurnAfterTheDraftDiedAnswersAgain(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $agent = $this->deadDraft($session);
    $this->assertSame(WorkflowStatus::Suspended, $agent->inspect()?->status);

    MockAiProvider::enqueue(new MockResponse(text: 'The draft did not finish.'));
    $state = $this->turn($session, 'Is the draft ready?');

    $this->assertSame('The draft did not finish.', $state->get('answer'));

    // An open call leaves the model a question nothing replied to. The new
    // turn answers the call, so the model reads a settled turn.
    $results = $session->conversationToolResults();
    $this->assertCount(1, $results);
    $this->assertSame('drafting_draft_content', $results[0]['name']);
    $this->assertStringContainsString('interrupted', json_encode($results[0]['result']));
  }

  /**
   * A drafter reads the editor's message once, not twice.
   *
   * The task carries the conversation of the session, and the message that
   * asked for the draft is the last turn of it. A task that repeated the
   * message would pay for it twice in every group of every draft.
   */
  public function testDrafterReadsTheEditorsMessageOnce(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();

    $this->turn($session, 'Write about the floods in Valencia.');

    $rows = $session->conversationThreads()[$this->thread($session, 1, 'main_fields')];
    $task = (string) ($rows[0]->getContent()[0]['content'] ?? '');
    $this->assertSame(1, substr_count($task, 'Write about the floods in Valencia.'));
  }

  /**
   * A drafter is not told the turns Neuron archived.
   *
   * Neuron trims a thread to the context window of its agent and stamps
   * what it drops. A drafter told every stored turn grows with the session
   * until the call overflows and every group fails.
   */
  public function testDrafterSkipsTheTurnsNeuronArchived(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue(new MockResponse(text: 'Tell me when you are ready.'));
    $this->turn($session, 'We are writing about the floods in Valencia.');

    // The turn above wrote the question and the answer, and both fall out
    // of the window here.
    $this->container->get(EditorialMessageStore::class)
      ->forSession($session)
      ->archive($session->editorialThread(), 2);

    $this->enqueueTurn();
    $this->turn($session, 'Draft it now.');

    $rows = $session->conversationThreads()[$this->thread($session, 1, 'main_fields')];
    $task = (string) ($rows[0]->getContent()[0]['content'] ?? '');
    $this->assertStringContainsString('Draft it now.', $task);
    $this->assertStringNotContainsString('floods in Valencia', $task);
  }

  /**
   * A second draft in one turn takes the version after the first.
   *
   * Two drafts that take one version reach the editor under one name. The
   * agent can ask for a second draft once it reads the first, and a retry
   * after a draft that failed is the likely case. So every call takes a
   * version of its own.
   */
  public function testSecondDraftInOneTurnTakesTheNextVersion(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    // The reply that reads the first draft asks for a second one.
    MockAiProvider::enqueue($this->toolCall('call_2'));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "T2"}], "field_teaser": [{"value": "S2"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 2 is ready.'));

    $this->turn($session, 'Draft it, then draft it again.');

    $this->assertSame(['Draft 1', 'Draft 2'], array_column($session->listDrafts(), 'name'));
    $this->assertSame([['value' => 'T']], $session->getDraftContent(1)['fields']['title']);
    $this->assertSame([['value' => 'T2']], $session->getDraftContent(2)['fields']['title']);
  }

  /**
   * The transcript reports a draft that died as failed.
   *
   * The card would draw this call as a draft still running. A later turn
   * settles the call, and the card would then draw a draft that landed. No
   * draft exists in either state. So the transcript reports the failure in
   * both.
   */
  public function testTranscriptReportsTheDraftThatDiedAsFailed(): void {
    $owner = $this->createUser(['use oe ai assistant', 'view_update own sessions']);
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);

    MockAiProvider::reset();
    $this->deadDraft($session);

    // Nothing settled the call yet, which is the state a reload reads.
    $result = $this->transcriptDraftCall($session);
    $this->assertSame('failed', $result['status']);
    $this->assertSame('The draft did not finish.', $result['error']);

    MockAiProvider::enqueue(new MockResponse(text: 'The draft did not finish.'));
    $this->turn($session, 'Is the draft ready?');

    // The turn settled the call with the interruption the library reports.
    $result = $this->transcriptDraftCall($session);
    $this->assertSame('failed', $result['status']);
    $this->assertStringContainsString('interrupted', $result['error']);
  }

  /**
   * Leaves the agent on the call that asks for a draft.
   *
   * Nothing writes the draft and nothing submits the result. A process that
   * dies while it drafts a group leaves the session in this state.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the turn belongs to.
   *
   * @return \NeuronAI\Agent\AgentInterface
   *   The agent that asked.
   */
  private function deadDraft(AiEditorialSessionInterface $session): AgentInterface {
    MockAiProvider::enqueue($this->toolCall());
    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session]);
    iterator_to_array($agent->stream(new UserMessage('Please draft it, go ahead.')), FALSE);

    return $agent;
  }

  /**
   * The result the transcript reports for the session's drafting call.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose transcript to read.
   *
   * @return array
   *   The result of the one drafting call the transcript holds.
   */
  private function transcriptDraftCall(AiEditorialSessionInterface $session): array {
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');
    $request = Request::create('/', 'POST', content: json_encode([
      'sessionId' => (string) $session->id(),
    ]));

    $results = [];
    foreach ($plugin->executeAction('get-messages', $request)['messages'] as $message) {
      foreach ($message['toolCalls'] ?? [] as $call) {
        if (($call['function']['name'] ?? '') === 'drafting_draft_content') {
          $results[] = $call['result'] ?? [];
        }
      }
    }
    $this->assertCount(1, $results, 'The transcript holds one drafting call.');

    return reset($results);
  }

  /**
   * Runs one turn of a session and returns the state it ended in.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the turn belongs to.
   * @param string $message
   *   What the editor sent.
   *
   * @return \NeuronAI\Workflow\WorkflowState
   *   The state of the finished run.
   */
  private function turn(AiEditorialSessionInterface $session, string $message): WorkflowState {
    return $this->container->get(NeuronWorkflowManagerInterface::class)
      ->createWorkflow('editorial_turn', [], ['session' => $session])
      ->run(ExecutionRequest::start(new ChatTurnEvent($message)));
  }

  /**
   * The thread one group of one run drafts in.
   *
   * A run names itself with a random segment, so a test reads the name back
   * off the session rather than composing it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the run drafts for.
   * @param int $version
   *   The version the run drafts.
   * @param string $group
   *   The field group.
   *
   * @return string
   *   The thread name.
   */
  private function thread(AiEditorialSessionInterface $session, int $version, string $group): string {
    $pattern = sprintf(
      '@^group_drafter\.session_%s\.draft_%d\.[A-Za-z0-9_-]{6}\.group_%s$@',
      $session->id(),
      $version,
      preg_quote($group, '@'),
    );
    $threads = preg_grep($pattern, array_keys($session->conversationThreads()));
    $this->assertCount(1, $threads, sprintf('One thread drafted "%s" for draft %d.', $group, $version));

    return (string) reset($threads);
  }

  /**
   * The main_fields answer of a news article.
   */
  private const MAIN_FIELDS = '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}';

  /**
   * The paragraphs answer, matching the text block branch of the schema.
   */
  private const PARAGRAPHS = '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>B</p>"}],'
    . ' "type": [{"target_id": "text_block"}]}]}';

  /**
   * Queues the responses of a turn that drafts both groups on first ask.
   *
   * @param string $answer
   *   What the editorial agent tells the editor about the draft it stored.
   */
  private function enqueueTurn(string $answer = 'Draft 1 is ready.'): void {
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: $answer));
  }

  /**
   * The editorial agent's call of the drafting tool, which takes no inputs.
   *
   * @param string $callId
   *   The id the model gives the call. A turn that asks twice needs a
   *   different one for each call.
   */
  private function toolCall(string $callId = 'call_1'): MockResponse {
    return new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
      ],
    );
  }

}
