<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Drives one whole turn of an editorial session that amends a draft.
 *
 * Mirrors DraftingTurnTest's shape: the turn runs the agent the chat
 * endpoint runs, so this covers the model, the deferred call, the
 * amend_content workflow and the group drafter together.
 *
 * @group oe_ai_assistant
 */
class AmendingTurnTest extends AiEditorialSessionKernelTestBase {

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
   * A turn amends a draft into the next minor version, redrafting every group.
   */
  public function testTurnAmendsDraftIntoTheNextMinorVersion(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    $this->enqueueAmend('1.0');
    $state = $this->turn($session, 'Make the title punchier.');

    $this->assertSame('Draft 1.1 is ready.', $state->getMessage()?->getContent());

    $results = $session->conversationToolResults();
    $this->assertCount(2, $results);
    $this->assertSame('amend_draft', $results[1]['name']);
    $this->assertSame(
      ['draft' => 'Draft 1.1', 'version' => '1.1', 'amends' => '1.0'],
      $results[1]['result'],
    );

    $this->assertSame('1.0', $session->getDraftRun('1.1')['amends']);
    $this->assertNotSame([], $session->getDraftRun('1.1')['schemaGroups']);

    // Every value here comes from the amendment's own answers (T2, S2, B2),
    // so neither group was quietly carried over from draft 1.0 instead of
    // being redrafted.
    $this->assertSame([
      'title' => [['value' => 'T2']],
      'field_teaser' => [['value' => 'S2']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B2</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $session->getDraftContent('1.1')['fields']);
  }

  /**
   * Amending tells the drafter what the editor asked for in this turn.
   *
   * The group drafter sees none of the editorial conversation, only the
   * task it is handed. Without the brief passed explicitly, it has no
   * request to act on and either leaves the field as it is or invents a
   * change nobody asked for.
   */
  public function testAmendingTellsTheDrafterWhatTheEditorAskedForInThisTurn(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    $this->enqueueAmend('1.0', ['main_fields'], 'Change the title to Zeus is a king.');
    $this->turn($session, 'Change the title to Zeus is a king.');

    $threads = $session->conversationThreads();
    $mainFieldsThreads = preg_grep(
      '@\.amend_1\.1\.[A-Za-z0-9_-]{6}\.group_main_fields$@',
      array_keys($threads),
    );
    $this->assertCount(1, $mainFieldsThreads, 'One thread drafted main_fields for the amendment.');
    $task = (string) ($threads[reset($mainFieldsThreads)][0]->getContent()[0]['content'] ?? '');

    $this->assertStringContainsString('What the editor asked for:', $task);
    $this->assertStringContainsString('Change the title to Zeus is a king.', $task);
  }

  /**
   * A draft stored before schemaGroups existed still amends.
   */
  public function testAmendingDraftStoredBeforeSchemaGroupsExistedFallsBackToCurrentGroups(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    $this->stripSchemaGroups($session, '1.0');
    $this->assertArrayNotHasKey('schemaGroups', (array) $session->getDraftRun('1.0'));

    MockAiProvider::reset();
    $this->enqueueAmend('1.0');
    $this->turn($session, 'Fix the title.');

    $this->assertSame(
      ['draft' => 'Draft 1.1', 'version' => '1.1', 'amends' => '1.0'],
      $session->conversationToolResults()[1]['result'],
    );

    // T2, S2 and B2 are the amendment's own answers, so the fallback really
    // did resolve to the session's live groups and both groups were
    // redrafted against them.
    $this->assertSame([
      'title' => [['value' => 'T2']],
      'field_teaser' => [['value' => 'S2']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B2</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $session->getDraftContent('1.1')['fields']);
  }

  /**
   * Two amend calls in one reply each get their own run and their own thread.
   */
  public function testTwoAmendCallsInOneReplyEachGetTheirOwnRun(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->twoAmendCalls('call_1', 'call_2', '1.0'));
    MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: 'Drafts 1.1 and 1.2 are ready.'));

    $this->turn($session, 'Amend it twice.');

    $results = $session->conversationToolResults();
    $this->assertCount(3, $results);
    $this->assertSame('1.1', $results[1]['result']['version']);
    $this->assertSame('1.2', $results[2]['result']['version']);

    // The second call reads nextMinorVersion() after the first has stored
    // its draft, so the two amendments take 1.1 and 1.2 and their drafters
    // run in threads named after separate runs. Sharing a run would let the
    // second amendment's drafters continue the first one's conversation.
    $threads = array_keys($session->conversationThreads());
    $this->assertCount(1, preg_grep(
      sprintf('@\\.amend_1\\.1\\.[A-Za-z0-9_-]{6}\\.group_main_fields$@'),
      $threads,
    ));
    $this->assertCount(1, preg_grep(
      sprintf('@\\.amend_1\\.2\\.[A-Za-z0-9_-]{6}\\.group_main_fields$@'),
      $threads,
    ));
  }

  /**
   * Naming a group amends only it; every other group keeps its source values.
   */
  public function testAmendingNamedGroupsCarriesTheRest(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    $this->enqueueAmend('1.0', ['main_fields']);
    $this->turn($session, 'Just punch up the title.');

    // main_fields was redrafted; field_content_paragraphs kept the seed
    // draft's own value rather than being reset or redrafted.
    $this->assertSame([
      'title' => [['value' => 'T2']],
      'field_teaser' => [['value' => 'S2']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $session->getDraftContent('1.1')['fields']);

    $groups = (array) $session->getDraftRun('1.1')['groups'];
    $carried = array_values(array_filter(
      $groups,
      static fn (array $group): bool => $group['id'] === 'field_content_paragraphs',
    ));
    $this->assertSame('carried', $carried[0]['status']);
  }

  /**
   * Naming only groups absent from the draft's schema fails cleanly.
   */
  public function testAmendingOnlyUnknownGroupNamesReportsFailureWithoutDrafting(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->amendCall('call_1', '1.0', ['no_such_group']));
    MockAiProvider::enqueue(new MockResponse(text: 'That group does not exist.'));

    $this->turn($session, 'Fix the no_such_group field.');

    $results = $session->conversationToolResults();
    $this->assertSame(
      [
        'error' => 'None of the named groups exist in draft 1. Available'
        . ' groups: main_fields, field_content_paragraphs.',
        'version' => '1.0',
        'status' => 'failed',
      ],
      $results[1]['result'],
    );
    // No new draft was stored, and draft 1.0 itself is untouched.
    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'));
  }

  /**
   * A group that never matches its schema fails the amendment and records why.
   */
  public function testAmendingGroupThatNeverMatchesFailsAndRecordsNoHeaderError(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    $this->enqueueTurn();
    $this->turn($session, 'Please draft it, go ahead.');

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->amendCall('call_1', '1.0'));
    // The first answer plus the five corrections RetryNode allows, none of
    // them naming a field of the group, so the retries run out.
    for ($i = 0; $i < 6; $i++) {
      MockAiProvider::enqueue(new MockResponse(text: '{"nope": []}'));
    }
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields could not be amended.'));

    $this->turn($session, 'Fix the title.');

    $results = $session->conversationToolResults();
    $this->assertSame('main_fields', $results[1]['result']['group']);
    $this->assertStringContainsString('does not match its schema', $results[1]['result']['error']);
    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'), 'An incomplete amendment produces no usable draft.');

    // main_fields records its own error on the groups array, so the stored
    // record's own 'error' stays empty. The key still has to be there: the
    // frontend's draft record declares 'error' as always present.
    $stored = $session->getDraftRun('1.1');
    $this->assertArrayHasKey('error', $stored);
    $this->assertSame('', $stored['error']);
  }

  /**
   * Amending an unknown version fails cleanly and drafts nothing.
   */
  public function testAmendingUnknownVersionReportsFailureWithoutDrafting(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::reset();
    MockAiProvider::enqueue($this->amendCall('call_1', '1.0'));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 does not exist in this session.'));

    $this->turn($session, 'Fix the title of draft 1.');

    $this->assertSame(
      ['error' => 'Draft 1 does not exist in this session.', 'version' => '1.0', 'status' => 'failed'],
      $session->conversationToolResults()[0]['result'],
    );
    $this->assertSame([], $session->listDrafts());
  }

  /**
   * Rewrites a stored draft row to drop 'schemaGroups'.
   *
   * This leaves the row looking like a draft stored before that key existed,
   * which is what the fallback to the session's live groups is there for.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the draft belongs to.
   * @param string $version
   *   The version to rewrite.
   */
  private function stripSchemaGroups(AiEditorialSessionInterface $session, string $version): void {
    $rows = \Drupal::entityTypeManager()->getStorage('neuron_message')->loadByProperties([
      'oe_ai_session' => (int) $session->id(),
      'thread_id' => $session->draftsThread(),
      'oe_draft_version' => $version,
    ]);
    $row = reset($rows);
    $content = $row->getContent();
    $draft = json_decode((string) ($content[0]['content'] ?? ''), TRUE);
    unset($draft['schemaGroups']);
    $content[0]['content'] = json_encode($draft, JSON_THROW_ON_ERROR);
    $row->set('content', json_encode($content, JSON_THROW_ON_ERROR));
    $row->save();
  }

  /**
   * Runs one turn of a session and returns the state it ended in.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the turn belongs to.
   * @param string $message
   *   What the editor sent.
   *
   * @return \NeuronAI\Agent\AgentState
   *   The state of the finished run.
   */
  private function turn(AiEditorialSessionInterface $session, string $message): AgentState {
    return $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session])
      ->chat(new UserMessage($message), TRUE);
  }

  /**
   * The brief a queued drafting tool call sends when the test gives none.
   */
  private const BRIEF = 'Draft it, go ahead.';

  /**
   * The main_fields answer the seed draft of a news article is built from.
   */
  private const MAIN_FIELDS = '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}';

  /**
   * The seed draft's paragraphs answer, matching the text block schema.
   */
  private const PARAGRAPHS = '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>B</p>"}],'
    . ' "type": [{"target_id": "text_block"}]}]}';

  /**
   * The amendment's main_fields answer, distinct from the seed draft's.
   */
  private const AMENDED_MAIN_FIELDS = '{"title": [{"value": "T2"}], "field_teaser": [{"value": "S2"}]}';

  /**
   * The amendment's paragraphs answer, distinct from the seed draft's.
   */
  private const AMENDED_PARAGRAPHS = '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>B2</p>"}],'
    . ' "type": [{"target_id": "text_block"}]}]}';

  /**
   * Queues the responses of a turn that drafts both groups on first ask.
   *
   * @param string $answer
   *   What the editorial agent tells the editor about the draft it stored.
   */
  private function enqueueTurn(string $answer = 'Draft 1 is ready.'): void {
    MockAiProvider::enqueue($this->toolCall('call_1', self::BRIEF));
    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    MockAiProvider::enqueue(new MockResponse(text: $answer));
  }

  /**
   * Queues the responses of a turn that amends a draft.
   *
   * @param string $version
   *   The version to amend.
   * @param array $groups
   *   The field group IDs to amend. Empty amends every group.
   * @param string $brief
   *   What the editor asked to change, in their own words. Empty omits the
   *   argument.
   * @param string $answer
   *   What the editorial agent tells the editor about the amendment.
   * @param string $callId
   *   The id the model gives the call.
   */
  private function enqueueAmend(string $version, array $groups = [], string $brief = '', string $answer = 'Draft 1.1 is ready.', string $callId = 'call_1'): void {
    MockAiProvider::enqueue($this->amendCall($callId, $version, $groups, $brief));
    if ($groups === [] || in_array('main_fields', $groups, TRUE)) {
      MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_MAIN_FIELDS));
    }
    if ($groups === [] || in_array('field_content_paragraphs', $groups, TRUE)) {
      MockAiProvider::enqueue(new MockResponse(text: self::AMENDED_PARAGRAPHS));
    }
    MockAiProvider::enqueue(new MockResponse(text: $answer));
  }

  /**
   * The editorial agent's call of the drafting tool, with its brief.
   *
   * @param string $callId
   *   The id the model gives the call.
   * @param string $brief
   *   What the tool call says the editor asked for.
   */
  private function toolCall(string $callId, string $brief): MockResponse {
    return new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => [
            'name' => 'drafting_draft_content',
            'arguments' => json_encode(['brief' => $brief], JSON_THROW_ON_ERROR),
          ],
        ],
      ],
    );
  }

  /**
   * The editorial agent's call of the amend tool.
   *
   * @param string $callId
   *   The id the model gives the call.
   * @param string $version
   *   The version to amend.
   * @param array $groups
   *   The field group IDs to amend. Empty omits the argument, amending every
   *   group.
   * @param string $brief
   *   What the editor asked to change. Empty omits the argument.
   */
  private function amendCall(string $callId, string $version, array $groups = [], string $brief = ''): MockResponse {
    $arguments = ['version' => $version];
    if ($groups !== []) {
      $arguments['groups'] = $groups;
    }
    if ($brief !== '') {
      $arguments['brief'] = $brief;
    }

    return new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => [
            'name' => 'amend_draft',
            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
          ],
        ],
      ],
    );
  }

  /**
   * A reply that asks to amend one draft twice, under the call ids given.
   *
   * @param string $first
   *   The id of the first call.
   * @param string $second
   *   The id of the second call.
   * @param string $version
   *   The version both calls amend.
   */
  private function twoAmendCalls(string $first, string $second, string $version): MockResponse {
    $call = static fn (string $id): array => [
      'id' => $id,
      'type' => 'function',
      'function' => [
        'name' => 'amend_draft',
        'arguments' => json_encode(['version' => $version], JSON_THROW_ON_ERROR),
      ],
    ];

    return new MockResponse(toolCalls: [$call($first), $call($second)]);
  }

}
