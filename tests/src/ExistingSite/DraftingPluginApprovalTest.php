<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration tests for saving a draft through the gated agent tool.
 *
 * The agent calls drafting_save_draft, and the call waits for the editor
 * before anything is written.
 */
class DraftingPluginApprovalTest extends DraftingPluginTestBase {

  private const string NO_WAITING_CALL = 'No call waits for that decision.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->trackEntityType('node');
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $this->deleteTestEntities();
    parent::tearDown();
  }

  /**
   * Tests that a save request suspends the agent and writes nothing.
   */
  public function testSaveRequestWaitsForTheEditor(): void {
    $session = $this->sessionWithDraft();

    $events = $this->askToSave($session, 1);

    $this->assertContains('finish', array_column($events, 'type'));
    $this->assertNotContains('tool-output-available', array_column($events, 'type'));
    $run = $this->editorialRun($session);
    $this->assertNotNull($run);
    $this->assertSame(WorkflowStatus::Suspended, $run->status);
    $this->assertInstanceOf(ApprovalRequest::class, $run->interrupt);
    $action = $run->interrupt->getActions()[0];
    $this->assertSame(
      ['call_save', 'drafting_save_draft', ['version' => '1']],
      [$action->id, $action->name, $action->inputs],
    );
    $this->assertSame([], $this->newNodeIds());

    $log = MockAiProvider::getCallLog();
    $offered = array_merge([], ...array_map(
      static fn (array $call): array => array_column(array_column($call['tools'] ?? [], 'function'), 'name'),
      $log,
    ));
    $this->assertContains('drafting_save_draft', $offered);
    $this->assertStringContainsString('call drafting_save_draft', $log[0]['system_prompt']);
  }

  /**
   * Tests that a version with no draft waits, then answers its error.
   */
  public function testUnknownVersionIsGatedThenAnswersTheError(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 9);
    $this->assertSame(WorkflowStatus::Suspended, $this->editorialRun($session)?->status);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 9 does not exist.'));

    $callId = $this->waitingCallId($session);
    $decided = $this->decide($session, $callId, TRUE);

    $output = $this->toolOutput($decided['events'], $callId);
    $this->assertSame(
      ['version' => '9', 'error' => 'Draft 9 does not exist in this session.'],
      json_decode($output['output'], TRUE),
    );
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a non-numeric version is gated like any other, then fails.
   *
   * A version is a free-form "major.minor" string, so there's no type that
   * rejects "two" up front. The call waits for the editor like any other
   * save and only fails once they decide it, the way an unknown version
   * does.
   */
  public function testNonNumericVersionIsGatedThenAnswersTheError(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 'two');
    MockAiProvider::enqueue(new MockResponse(text: 'I need a draft number.'));

    $callId = $this->waitingCallId($session);
    $decided = $this->decide($session, $callId, TRUE);

    $output = $this->toolOutput($decided['events'], $callId);
    $this->assertSame(
      ['version' => 'two', 'error' => 'Draft 0 does not exist in this session.'],
      json_decode($output['output'], TRUE),
    );
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a draft with no fields waits, then answers its error.
   */
  public function testEmptyDraftIsGatedThenAnswersTheError(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, []);
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 does not exist.'));

    $callId = $this->waitingCallId($session);
    $decided = $this->decide($session, $callId, TRUE);

    $output = $this->toolOutput($decided['events'], $callId);
    $this->assertSame(
      ['version' => '1', 'error' => 'Draft 1 does not exist in this session.'],
      json_decode($output['output'], TRUE),
    );
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that nothing waits in a session where no save was asked for.
   */
  public function testNothingWaitsWhenNoSaveWasAsked(): void {
    $session = $this->sessionWithDraft();

    $this->assertSame([], $this->getApprovals($session));
  }

  /**
   * Tests that the transcript carries a save that waits for the editor.
   */
  public function testWaitingSaveIsInTheTranscript(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));
    $this->assertCount(1, $calls);
    $this->assertSame('drafting_save_draft', $calls[0]['function']['name']);
    $this->assertSame($this->waitingCallId($session), $calls[0]['callId']);
    $this->assertSame(['version' => '1'], json_decode($calls[0]['function']['arguments'], TRUE));
    $this->assertTrue($calls[0]['awaitingDecision']);
    $this->assertArrayNotHasKey('result', $calls[0]);
  }

  /**
   * Tests that a save nobody can decide any more stops waiting.
   */
  public function testDroppedSaveStopsWaiting(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Nothing was saved.'));

    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Tell me about the draft instead.',
      'sessionId' => $session->id(),
    ]);

    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));
    $this->assertSame([], array_column($calls, 'awaitingDecision'));
  }

  /**
   * Tests that an approved save writes an unpublished revision.
   */
  public function testApprovedSaveWritesAnUnpublishedRevision(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is saved.'));

    $callId = $this->waitingCallId($session);
    $decided = $this->decide($session, $callId, TRUE);

    $this->assertSame(200, $decided['status']);
    $result = json_decode($this->toolOutput($decided['events'], $callId)['output'], TRUE);
    $this->assertSame('1', $result['version']);
    $node = Node::load($result['nodeId']);
    $this->assertSame('Draft one', $node->getTitle());
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertSame([], $this->getApprovals($session));
    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));
    $this->assertSame(['drafting_save_draft'], array_column(array_column($calls, 'function'), 'name'));
    $this->assertSame(['version', 'nodeId', 'previewUrl', 'draftName'], array_keys($calls[0]['result']));
  }

  /**
   * Tests that an approved save is in the activity record of the session.
   */
  public function testApprovedSavesAreInTheActivityRecord(): void {
    $session = $this->sessionWithDraft([
      'use oe ai assistant',
      'create oe_news content',
      'edit own oe_news content',
      'use editorial transition create_new_draft',
    ]);
    $this->seedDraft($session, 2, ['title' => [['value' => 'Draft two']]]);
    // Creating the session records an entry of its own, and the assertions
    // below cover the whole record. Delete it so only the saves remain.
    $session->deleteActivity();

    $this->askToSave($session, 1, 'call_save_1', 'Draft 1 is saved.');
    $this->assertSame(200, $this->decide($session, 'call_save_1', TRUE)['status']);
    $this->askToSave($session, 2, 'call_save_2', 'Draft 2 is saved.');
    $this->assertSame(200, $this->decide($session, 'call_save_2', TRUE)['status']);

    $nodeId = reset($this->newNodeIds());
    $entries = $session->getLatestActivity(100);
    $this->assertSame([
      sprintf('Draft 1 saved to the CMS as a new node (node %s).', $nodeId),
      sprintf('Draft 2 saved to the CMS as a new revision of node %s.', $nodeId),
    ], array_map(
      static fn (AiEditorialSessionActivityInterface $entry): string => $entry->getMessage(),
      $entries,
    ));
    $this->assertSame(
      [ActivityType::DraftSaved, ActivityType::DraftSaved],
      array_map(
        static fn (AiEditorialSessionActivityInterface $entry): ActivityType => $entry->getType(),
        $entries,
      ),
    );
  }

  /**
   * Tests that a cancelled save writes nothing and a later one waits again.
   */
  public function testCancelledSaveWritesNothingAndAsksAgainLater(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is not saved.'));
    $callId = $this->waitingCallId($session);

    $decided = $this->decide($session, $callId, FALSE);

    $this->assertSame(200, $decided['status']);
    $this->assertSame([], $this->newNodeIds());
    $this->assertSame([], $this->getApprovals($session));

    $this->askToSave($session, 1, 'call_save_2');
    $this->assertSame('call_save_2', $this->getApprovals($session)[0]['callId']);
    // The cancel reaches the model as the result of the call it answered.
    $history = json_encode(end(MockAiProvider::getCallLog())['messages']);
    $this->assertStringContainsString('cancelled', $history);
    $this->assertStringNotContainsString('Do not attempt this tool again', $history);
  }

  /**
   * Tests that an approved save the editor may not write answers why.
   */
  public function testApprovedSaveWithoutPermissionAnswersTheSaversMessage(): void {
    $session = $this->sessionWithDraft(['use oe ai assistant']);
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is not saved.'));

    $callId = $this->waitingCallId($session);
    $decided = $this->decide($session, $callId, TRUE);

    $this->assertSame(200, $decided['status']);
    $this->assertSame(
      ['version' => '1', 'error' => 'You do not have permission to create oe_news content.'],
      json_decode($this->toolOutput($decided['events'], $callId)['output'], TRUE),
    );
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that two saves of one reply wait for a decision each.
   */
  public function testTwoSavesInOneReplyAreDecidedOneByOne(): void {
    $session = $this->sessionWithDraft();
    $this->seedDraft($session, 2, ['title' => [['value' => 'Draft two']]]);
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_a',
          'type' => 'function',
          'function' => ['name' => 'drafting_save_draft', 'arguments' => json_encode(['version' => 1])],
        ],
        [
          'id' => 'call_b',
          'type' => 'function',
          'function' => ['name' => 'drafting_save_draft', 'arguments' => json_encode(['version' => 2])],
        ],
      ],
    ));
    $asked = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Save drafts 1 and 2.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $asked['status'], substr($asked['body'], 0, 500));
    $this->assertCount(2, $this->getApprovals($session));

    $this->assertSame(200, $this->decide($session, 'call_a', TRUE)['status']);
    $this->assertSame([], $this->newNodeIds());
    $this->assertCount(1, $this->getApprovals($session));

    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is saved, draft 2 is not.'));
    $this->assertSame(200, $this->decide($session, 'call_b', FALSE)['status']);
    $nodeIds = $this->newNodeIds();
    $this->assertCount(1, $nodeIds);
    $this->assertSame('Draft one', Node::load(reset($nodeIds))->getTitle());
  }

  /**
   * Tests that the save action no longer exists.
   */
  public function testSaveActionIsGone(): void {
    $session = $this->sessionWithDraft();

    $result = $this->httpPost('/api/ai/plugins/drafting/save', [
      'sessionId' => $session->id(),
      'version' => 1,
    ]);

    $this->assertSame(404, $result['status'], substr($result['body'], 0, 500));
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a second decision on the same save is refused.
   */
  public function testRepeatedDecisionIsRefused(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is saved.'));
    $callId = $this->waitingCallId($session);
    $this->assertSame(200, $this->decide($session, $callId, TRUE)['status']);

    $refused = $this->decide($session, $callId, TRUE);
    $this->assertSame(400, $refused['status']);
    $this->assertSame(self::NO_WAITING_CALL, $refused['message']);
    $this->assertCount(1, $this->newNodeIds());
  }

  /**
   * Tests that a user without access to the session cannot read or decide.
   */
  public function testOtherUserCannotReadOrDecideTheWaitingSave(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    $owner = $this->loggedInUser;

    $this->loginUser($this->createUser(['use oe ai assistant', 'create oe_news content']));

    $result = $this->httpPost('/api/ai/plugins/drafting/get-messages', ['sessionId' => $session->id()]);
    $this->assertSame(403, $result['status']);
    $this->assertSame(403, $this->decide($session, $this->waitingCallId($session), TRUE)['status']);
    $this->assertSame([], $this->newNodeIds());

    // The owner still sees the save waiting.
    $this->loginUser($owner);
    $this->assertCount(1, $this->getApprovals($session));
  }

  /**
   * Tests that a malformed decision is refused and the save keeps waiting.
   */
  #[DataProvider('malformedDecisions')]
  public function testMalformedDecisionIsRefused(mixed $decision, string $code): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'sessionId' => $session->id(),
      'runConfig' => ['custom' => ['approval' => $decision]],
    ]);

    $this->assertSame(400, $result['status'], substr($result['body'], 0, 500));
    $body = json_decode($result['body'], TRUE);
    // The contract refuses a decision of the wrong shape, and the action
    // refuses one that names a call nothing waits on.
    $this->assertSame($code, $body['code']);
    $this->assertCount(1, $this->getApprovals($session));
  }

  /**
   * Decisions the chat action refuses.
   */
  public static function malformedDecisions(): array {
    return [
      'answer not one of the two' => [['callId' => 'call_save', 'decision' => 'maybe'], 'bad_request'],
      'no call id' => [['decision' => 'approve'], 'bad_request'],
      'call id not a string' => [['callId' => 1, 'decision' => 'approve'], 'bad_request'],
      'not an object' => ['approve', 'bad_request'],
      'unknown call' => [['callId' => 'call_nope', 'decision' => 'approve'], 'invalid_request'],
    ];
  }

  /**
   * Creates a logged-in user's session holding "Draft 1".
   *
   * @param array $permissions
   *   The permissions of the user.
   */
  private function sessionWithDraft(array $permissions = ['use oe ai assistant', 'create oe_news content']): AiEditorialSessionInterface {
    $user = $this->createUser($permissions);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, ['title' => [['value' => 'Draft one']]]);

    return $session;
  }

  /**
   * Has the model call the save tool, then posts the request to chat.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session to chat in.
   * @param int|string $version
   *   The version the model asks to save.
   * @param string $callId
   *   The id of the tool call.
   * @param string|null $reply
   *   The text the model answers with after the tool result, if any.
   *
   * @return array
   *   The parsed SSE events.
   */
  private function askToSave(AiEditorialSessionInterface $session, int|string $version, string $callId = 'call_save', ?string $reply = NULL): array {
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => [
            'name' => 'drafting_save_draft',
            'arguments' => json_encode(['version' => $version]),
          ],
        ],
      ],
    ));
    if ($reply !== NULL) {
      MockAiProvider::enqueue(new MockResponse(text: $reply));
    }

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => sprintf('Save draft %s.', $version),
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'], substr($result['body'], 0, 500));

    return $this->parseSseEvents($result['body']);
  }

  /**
   * Posts the editor's decision on a waiting save to chat.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose save to decide.
   * @param string $callId
   *   The id of the waiting call.
   * @param bool $save
   *   Whether the editor confirmed the save.
   *
   * @return array
   *   The response 'status', its parsed SSE 'events' and, for a refusal, its
   *   error 'message'.
   */
  private function decide(AiEditorialSessionInterface $session, string $callId, bool $save): array {
    // A decision turn ignores the message the client sends with it.
    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Ignored.',
      'sessionId' => $session->id(),
      'runConfig' => [
        'custom' => [
          'approval' => [
            'callId' => $callId,
            'decision' => $save ? 'approve' : 'reject',
          ],
        ],
      ],
    ]);
    if ($result['status'] !== 200) {
      $body = json_decode($result['body'], TRUE);

      return ['status' => $result['status'], 'events' => [], 'message' => $body['message'] ?? NULL];
    }
    $events = $this->parseSseEvents($result['body']);
    $this->assertNotContains('error', array_column($events, 'type'), json_encode(array_column($events, 'type')));

    return ['status' => 200, 'events' => $events, 'message' => NULL];
  }

  /**
   * Lists the transcript calls that wait for the editor's decision.
   *
   * The transcript is the only place that reports them, so the tests read
   * them the same way the editor's thread does.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose waiting saves to list.
   *
   * @return array
   *   One {callId, name, inputs} entry per waiting call.
   */
  private function getApprovals(AiEditorialSessionInterface $session): array {
    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));

    return array_values(array_map(
      static fn (array $call): array => [
        'callId' => $call['callId'],
        'name' => $call['function']['name'],
        'inputs' => json_decode($call['function']['arguments'], TRUE),
      ],
      array_filter($calls, static fn (array $call): bool => ($call['awaitingDecision'] ?? FALSE) === TRUE),
    ));
  }

  /**
   * The id of the save the run waits on.
   *
   * The provider's own id for a deferred call does not survive the round
   * trip, so the editor's decision names the id the run holds, which is
   * also the one the card carries.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose waiting save to name.
   * @param int $index
   *   Which waiting call to name, for a reply that asked more than once.
   *
   * @return string
   *   The call id.
   */
  private function waitingCallId(AiEditorialSessionInterface $session, int $index = 0): string {
    $actions = $this->editorialRun($session)?->interrupt?->getActions() ?? [];

    return (string) array_values($actions)[$index]->id;
  }

  /**
   * Reads the suspended or finished run of the editorial agent.
   */
  private function editorialRun(AiEditorialSessionInterface $session): ?WorkflowRunSnapshot {
    return (new WorkflowEngine(\Drupal::service(PersistenceInterface::class)))
      ->inspect($session->editorialThread());
  }

  /**
   * Returns the one tool output event of a call.
   *
   * A call that fails its input validation ends with an error event.
   *
   * @param array $events
   *   The parsed SSE events.
   * @param string $callId
   *   The id of the tool call.
   *
   * @return array
   *   The event.
   */
  private function toolOutput(array $events, string $callId): array {
    $outputs = array_values(array_filter(
      $events,
      static fn (array $event): bool => in_array($event['type'], ['tool-output-available', 'tool-output-error'], TRUE) && ($event['toolCallId'] ?? NULL) === $callId,
    ));
    $this->assertCount(1, $outputs, sprintf('One tool output for "%s" in %s.', $callId, json_encode(array_column($events, 'type'))));

    return $outputs[0];
  }

  /**
   * Returns the ids of the nodes created since setUp.
   *
   * @return array
   *   The node ids.
   */
  private function newNodeIds(): array {
    $current = \Drupal::entityTypeManager()->getStorage('node')
      ->getQuery()->accessCheck(FALSE)->execute();

    return array_values(array_diff($current, $this->existingEntityIds['node']));
  }

}
