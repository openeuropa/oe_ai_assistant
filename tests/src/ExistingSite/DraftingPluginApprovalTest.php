<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\node\Entity\Node;
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

  private const string NO_WAITING_SAVE = 'No save waits for that decision.';

  private const string BAD_DECISION = 'The decision must name a call and be approve or reject.';

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
      ['call_save', 'drafting_save_draft', ['version' => 1]],
      [$action->id, $action->name, $action->inputs],
    );
    $this->assertSame([], $this->newNodeIds());

    $log = MockAiProvider::getCallLog();
    $this->assertContains('drafting_save_draft', array_column(array_column($log[0]['tools'], 'function'), 'name'));
    $this->assertStringContainsString('call drafting_save_draft', $log[0]['system_prompt']);
  }

  /**
   * Tests that a version with no draft is answered without a decision.
   */
  public function testUnknownVersionIsNotGated(): void {
    $session = $this->sessionWithDraft();

    $events = $this->askToSave($session, 9, reply: 'Draft 9 does not exist.');

    $output = $this->toolOutput($events, 'call_save');
    $this->assertSame(
      ['version' => 9, 'error' => 'Draft 9 does not exist in this session.'],
      json_decode($output['output'], TRUE),
    );
    $this->assertNotSame(WorkflowStatus::Suspended, $this->editorialRun($session)?->status);
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a version that is not an integer is not gated.
   */
  public function testNonIntegerVersionIsNotGated(): void {
    $session = $this->sessionWithDraft();

    $events = $this->askToSave($session, 'two', reply: 'I need a draft number.');

    $this->assertContains('finish', array_column($events, 'type'));
    $this->assertArrayHasKey('errorText', $this->toolOutput($events, 'call_save'));
    $this->assertNotSame(WorkflowStatus::Suspended, $this->editorialRun($session)?->status);
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a draft with no fields is answered without a decision.
   */
  public function testEmptyDraftIsNotGated(): void {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $this->seedDraft($session, 1, []);

    $events = $this->askToSave($session, 1, reply: 'Draft 1 does not exist.');

    $output = $this->toolOutput($events, 'call_save');
    $this->assertSame(
      ['version' => 1, 'error' => 'Draft 1 does not exist in this session.'],
      json_decode($output['output'], TRUE),
    );
    $this->assertNotSame(WorkflowStatus::Suspended, $this->editorialRun($session)?->status);
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that get-approvals lists the save that waits for the editor.
   */
  public function testGetApprovalsListsTheWaitingSave(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $this->assertSame(
      [['callId' => 'call_save', 'name' => 'drafting_save_draft', 'inputs' => ['version' => 1]]],
      $this->getApprovals($session),
    );
  }

  /**
   * Tests that get-approvals is empty when nothing waits.
   */
  public function testGetApprovalsIsEmptyWhenNothingWaits(): void {
    $session = $this->sessionWithDraft();

    $this->assertSame([], $this->getApprovals($session));
  }

  /**
   * Tests that a reset drops the save that waits for the editor.
   */
  public function testResetDropsTheWaitingSave(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $result = $this->httpPost('/api/ai/plugins/drafting/reset', ['sessionId' => $session->id()]);

    $this->assertEquals(200, $result['status'], substr($result['body'], 0, 500));
    $this->assertSame([], $this->getApprovals($session));
    $this->assertNull($this->editorialRun($session));
  }

  /**
   * Tests that a waiting save draws no card in the transcript.
   */
  public function testWaitingSaveDrawsNoCard(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));
    $this->assertNotContains('drafting_save_draft', array_column(array_column($calls, 'function'), 'name'));
  }

  /**
   * Tests that an approved save writes an unpublished revision.
   */
  public function testApprovedSaveWritesAnUnpublishedRevision(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is saved.'));

    $decided = $this->decide($session, 'call_save', 'approve');

    $this->assertSame(200, $decided['status']);
    $result = json_decode($this->toolOutput($decided['events'], 'call_save')['output'], TRUE);
    $this->assertSame(1, $result['version']);
    $node = Node::load($result['nodeId']);
    $this->assertSame('Draft one', $node->getTitle());
    $this->assertSame('draft', $node->get('moderation_state')->value);
    $this->assertSame([], $this->getApprovals($session));
    $calls = array_merge([], ...array_column($this->getMessages($session), 'toolCalls'));
    $this->assertSame(['drafting_save_draft'], array_column(array_column($calls, 'function'), 'name'));
    $this->assertSame(['version', 'nodeId', 'previewUrl'], array_keys($calls[0]['result']));
  }

  /**
   * Tests that a cancelled save writes nothing and a later one waits again.
   */
  public function testCancelledSaveWritesNothingAndAsksAgainLater(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is not saved.'));

    $decided = $this->decide($session, 'call_save', 'reject');

    $this->assertSame(200, $decided['status']);
    $this->assertSame([], $this->newNodeIds());
    $this->assertSame([], $this->getApprovals($session));
    $log = MockAiProvider::getCallLog();
    $this->assertStringContainsString('The editor cancelled this save.', json_encode(end($log)['messages']));

    $this->askToSave($session, 1, 'call_save_2');
    $this->assertSame('call_save_2', $this->getApprovals($session)[0]['callId']);
  }

  /**
   * Tests that an approved save the editor may not write answers why.
   */
  public function testApprovedSaveWithoutPermissionAnswersTheSaversMessage(): void {
    $session = $this->sessionWithDraft(['use oe ai assistant']);
    $this->askToSave($session, 1);
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is not saved.'));

    $decided = $this->decide($session, 'call_save', 'approve');

    $this->assertSame(200, $decided['status']);
    $this->assertSame(
      ['version' => 1, 'error' => 'You do not have permission to create oe_news content.'],
      json_decode($this->toolOutput($decided['events'], 'call_save')['output'], TRUE),
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
    $this->assertSame(['call_a', 'call_b'], array_column($this->getApprovals($session), 'callId'));

    $this->assertSame(200, $this->decide($session, 'call_a', 'approve')['status']);
    $this->assertSame([], $this->newNodeIds());
    $this->assertSame(['call_b'], array_column($this->getApprovals($session), 'callId'));

    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is saved, draft 2 is not.'));
    $this->assertSame(200, $this->decide($session, 'call_b', 'reject')['status']);
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
    $this->assertSame(200, $this->decide($session, 'call_save', 'approve')['status']);

    $refused = $this->decide($session, 'call_save', 'approve');
    $this->assertSame(400, $refused['status']);
    $this->assertSame(self::NO_WAITING_SAVE, $refused['message']);
    $this->assertCount(1, $this->newNodeIds());
  }

  /**
   * Tests that a decision after a reset is refused.
   */
  public function testDecisionAfterResetIsRefused(): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);
    $this->httpPost('/api/ai/plugins/drafting/reset', ['sessionId' => $session->id()]);

    $refused = $this->decide($session, 'call_save', 'approve');
    $this->assertSame(400, $refused['status']);
    $this->assertSame(self::NO_WAITING_SAVE, $refused['message']);
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Tests that a malformed decision is refused and the save keeps waiting.
   */
  #[DataProvider('malformedApprovals')]
  public function testMalformedApprovalIsRefused(mixed $approval, string $message): void {
    $session = $this->sessionWithDraft();
    $this->askToSave($session, 1);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'sessionId' => $session->id(),
      'runConfig' => ['custom' => ['approval' => $approval]],
    ]);

    $this->assertSame(400, $result['status'], substr($result['body'], 0, 500));
    $body = json_decode($result['body'], TRUE);
    $this->assertSame('invalid_request', $body['code']);
    $this->assertSame($message, $body['message']);
    $this->assertSame(['call_save'], array_column($this->getApprovals($session), 'callId'));
  }

  /**
   * Decisions the chat action refuses.
   */
  public static function malformedApprovals(): array {
    return [
      'unknown decision' => [
        ['callId' => 'call_save', 'decision' => 'maybe'],
        self::BAD_DECISION,
      ],
      'no call id' => [['decision' => 'approve'], self::BAD_DECISION],
      'call id not a string' => [
        ['callId' => 1, 'decision' => 'approve'],
        self::BAD_DECISION,
      ],
      'not an object' => ['approve', self::BAD_DECISION],
      'unknown call' => [
        ['callId' => 'call_nope', 'decision' => 'approve'],
        self::NO_WAITING_SAVE,
      ],
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
   * @param string $decision
   *   Either approve or reject.
   *
   * @return array
   *   The response 'status', its parsed SSE 'events' and, for a refusal, its
   *   error 'message'.
   */
  private function decide(AiEditorialSessionInterface $session, string $callId, string $decision): array {
    // A decision turn ignores the message the client sends with it.
    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Ignored.',
      'sessionId' => $session->id(),
      'runConfig' => ['custom' => ['approval' => ['callId' => $callId, 'decision' => $decision]]],
    ]);
    if ($result['status'] !== 200) {
      $body = json_decode($result['body'], TRUE);

      return ['status' => $result['status'], 'events' => [], 'message' => $body['message'] ?? NULL];
    }
    $events = $this->parseSseEvents($result['body']);
    $this->assertNotContains('error', array_column($events, 'type'));

    return ['status' => 200, 'events' => $events, 'message' => NULL];
  }

  /**
   * Calls the get-approvals action and returns its approvals list.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose waiting saves to list.
   *
   * @return array
   *   The approvals.
   */
  private function getApprovals(AiEditorialSessionInterface $session): array {
    $result = $this->httpPost('/api/ai/plugins/drafting/get-approvals', [
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'], substr($result['body'], 0, 500));

    return json_decode($result['body'], TRUE)['approvals'];
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
    $this->assertCount(1, $outputs, sprintf('One tool output for "%s".', $callId));

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
