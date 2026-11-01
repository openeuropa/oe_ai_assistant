<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\DraftContentNeuronTool;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Log\AbstractLogger;

/**
 * Drives one whole drafting turn through the agent manager.
 *
 * The chat endpoint still runs the old tool loop, so this is what proves the
 * agents, the tool and the group workflow produce a correct draft and a
 * correct transcript on their own.
 *
 * @todo Drive this through the endpoint when OEL-5072 moves it onto the
 *   agent.
 *
 * @group oe_ai_assistant
 */
class DraftingTurnTest extends AiEditorialSessionKernelTestBase {

  /**
   * The moment every run of these tests starts at.
   */
  private const NOW = 1793377032.481;

  /**
   * The run id a draft of that moment carries.
   */
  private const RUN = 'draft_%d.20261030T161712481Z';

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

    // A run takes the moment it starts as its name, so the clock stands
    // still and a run writes the threads the tests below assert.
    $this->freezeClock(self::NOW);
  }

  /**
   * A turn drafts every group in order, versions the draft and stores it.
   *
   * Without a template the latest one for oe_news applies, which splits into
   * main_fields (title, field_teaser) and field_content_paragraphs. So one
   * call of the drafting tool runs two group drafters.
   */
  public function testTurnDraftsEveryGroupAndVersionsTheDraft(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();

    $agent = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session]);
    $state = $agent->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $this->assertSame('Draft 1 is ready.', $state->getMessage()?->getContent());

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
    $this->assertArrayHasKey($this->thread($session, 1, 'main_fields'), $threads);
    $this->assertArrayHasKey($paragraphThread, $threads);

    // The paragraphs drafter reads what the main fields already say, so the
    // tool drafts the groups in order rather than in parallel.
    $task = (string) ($threads[$paragraphThread][0]->getContent()[0]['content'] ?? '');
    $this->assertStringContainsString('Main fields already generated', $task);
    $this->assertStringContainsString('"title":[{"value":"T"}]', $task);
  }

  /**
   * A second turn numbers its draft after the first.
   *
   * The version comes from the drafts stored for the session, which is what
   * the editor and the model both name a draft by.
   */
  public function testSecondTurnVersionsTheNextDraft(): void {
    $session = $this->createSession($this->createUser());
    $agentManager = $this->container->get(NeuronAgentManagerInterface::class);

    MockAiProvider::reset();
    $this->enqueueTurn();
    $agentManager->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    MockAiProvider::reset();
    $this->enqueueTurn('Draft 2 is ready.');
    $agentManager->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Draft it again.'), TRUE);

    $this->assertSame(['Draft 1', 'Draft 2'], array_column($session->listDrafts(), 'name'));
    $this->assertSame([1, 2], array_column($session->listDrafts(), 'version'));

    // The second run holds its own conversation, so a drafter never replays
    // the answer it gave for the draft before.
    $this->assertArrayHasKey($this->thread($session, 2, 'main_fields'), $session->conversationThreads());
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

    $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

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

    $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $results = $session->conversationToolResults();
    $this->assertSame('main_fields', $results[0]['result']['group']);
    $this->assertStringContainsString('does not match its schema', $results[0]['result']['error']);

    $this->assertSame([], $session->listDrafts(), 'An incomplete set produces no draft.');
  }

  /**
   * A byte no JSON string can carry fails the group and nothing else.
   *
   * The message store writes a model answer as JSON, and it writes no JSON
   * that holds a malformed byte, so such an answer stops its group. The turn
   * survives: the tool reports the group and the agent answers the editor.
   *
   * @todo Draft the group instead, once the ai_neuron store substitutes an
   *   invalid byte rather than refusing the message.
   */
  public function testAnswerWithInvalidUtf8FailsOnlyItsGroup(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "T' . chr(0xB1) . '"}], "field_teaser": [{"value": "S"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields could not be drafted.'));

    $state = $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $this->assertSame('The main fields could not be drafted.', $state->getMessage()?->getContent());
    $this->assertSame([
      'error' => 'The group could not be drafted.',
      'group' => 'main_fields',
    ], $session->conversationToolResults()[0]['result']);
    $this->assertSame([], $session->listDrafts());
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
        $this->records[] = strtr($message, $context);
      }

    });

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(error: new \RuntimeException('Gateway said 500: host db-77 refused')));
    MockAiProvider::enqueue(new MockResponse(text: 'The draft could not be generated.'));

    $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $results = $session->conversationToolResults();
    $this->assertSame([
      'error' => 'The group could not be drafted.',
      'group' => 'main_fields',
    ], $results[0]['result']);
    $this->assertSame([], $session->listDrafts());

    // What the site saw is in the log, with the run that saw it.
    $logged = implode("\n", $records);
    $this->assertStringContainsString('host db-77 refused', $logged);
    $this->assertStringContainsString('main_fields', $logged);
    $this->assertStringContainsString(sprintf(self::RUN, 1), $logged);
  }

  /**
   * A bare list says nothing about which field of a group it belongs to.
   */
  public function testBareListIsLeftOutOfAGroupOfSeveralFields(): void {
    $groups = [
      [
        'groupId' => 'field_people',
        'label' => 'People',
        'fieldNames' => ['field_authors', 'field_contacts'],
        'schemaSlice' => [],
      ],
    ];
    $answer = [['target_uuid' => 'p1'], ['target_uuid' => 'p2']];

    $assemble = new \ReflectionMethod(DraftContentNeuronTool::class, 'assemble');
    $fields = $assemble->invoke($this->tool(), $groups, ['field_people' => $answer]);

    $this->assertSame([], $fields, 'Neither field takes a list the answer did not name.');

    // The same answer is the value of a group that holds one field.
    $groups[0]['fieldNames'] = ['field_contacts'];
    $this->assertSame(
      ['field_contacts' => $answer],
      $assemble->invoke($this->tool(), $groups, ['field_people' => $answer]),
    );
  }

  /**
   * Builds the drafting tool, bound to a session of its own.
   */
  private function tool(): DraftContentNeuronTool {
    $plugin = $this->container->get(NeuronToolManagerInterface::class)->createTool(
      'drafting_draft_content',
      ['session' => $this->createSession($this->createUser()), 'thread' => 'drafting.session_1'],
    );
    $tool = (new \ReflectionProperty($plugin, 'plugin'))->getValue($plugin);
    assert($tool instanceof DraftContentNeuronTool);

    return $tool;
  }

  /**
   * A run that follows a failed one takes the version and its own threads.
   */
  public function testRunAfterFailedRunDraftsInNewThreads(): void {
    $session = $this->createSession($this->createUser());
    $agentManager = $this->container->get(NeuronAgentManagerInterface::class);

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->toolCall());
    for ($i = 0; $i < 6; $i++) {
      MockAiProvider::enqueue(new MockResponse(text: '{"nope": []}'));
    }
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields could not be drafted.'));
    $agentManager->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Please draft it, go ahead.'), TRUE);

    $this->assertSame([], $session->listDrafts());
    $failed = array_keys($session->conversationThreads());

    // The clock moved on, so the second run of the same version holds its
    // own conversations rather than reading the failed ones back.
    $this->freezeClock(self::NOW + 61);

    MockAiProvider::reset();
    $this->enqueueTurn();
    $agentManager->createAgent('drafting', ['session' => $session])
      ->chat(new UserMessage('Try again.'), TRUE);

    // The draft the failed run never stored is the one this run takes.
    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'));

    $threads = array_keys($session->conversationThreads());
    $this->assertNotEmpty(array_diff($threads, $failed), 'The second run drafts in threads of its own.');
    $this->assertContains('field_group.session_' . $session->id() . '.draft_1.20261030T161813481Z.group_main_fields', $threads);
  }

  /**
   * Stops the clock, so a run takes a name the test knows.
   *
   * @param float $now
   *   The moment every call of the run reads.
   */
  private function freezeClock(float $now): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentMicroTime')->willReturn($now);
    $time->method('getRequestMicroTime')->willReturn($now);
    $time->method('getCurrentTime')->willReturn((int) $now);
    $time->method('getRequestTime')->willReturn((int) $now);
    $this->container->set('datetime.time', $time);
  }

  /**
   * The thread one group of one run drafts in.
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
    return sprintf('field_group.session_%s.%s.group_%s', $session->id(), sprintf(self::RUN, $version), $group);
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
   *   What the drafting agent tells the editor about the draft it stored.
   */
  private function enqueueTurn(string $answer = 'Draft 1 is ready.'): void {
    MockAiProvider::enqueue($this->toolCall());
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: $answer));
  }

  /**
   * The drafting agent's call of the drafting tool, which takes no inputs.
   */
  private function toolCall(): MockResponse {
    return new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
      ],
    );
  }

}
