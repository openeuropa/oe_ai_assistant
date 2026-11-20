<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentDispatcher;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the background mode of subagents: the handle, the queue and the claim.
 */
#[Group('oe_ai_assistant')]
class SubagentWorkerTest extends AiEditorialSessionKernelTestBase {

  use ResearchDocumentTrait;

  /**
   * The main_fields answer of a news article.
   */
  private const string MAIN_FIELDS = '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}';

  /**
   * The paragraphs answer, matching the text block branch of the schema.
   */
  private const string PARAGRAPHS = '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>B</p>"}],'
    . ' "type": [{"target_id": "text_block"}]}]}';

  /**
   * The ledger.
   */
  private SubagentTaskLedger $ledger;

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
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('paragraph');
    $this->installConfig(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
        'chat_with_structured_response' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
    MockAiProvider::reset();
    $this->ledger = $this->container->get(SubagentTaskLedger::class);
  }

  /**
   * Tests that a background call answers with a handle and runs later.
   */
  public function testBackgroundCallReturnsHandle(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $id = $this->createExtractedDocument($session, 'Annual report', 'Funding is 5M euros.');

    MockAiProvider::enqueue($this->toolCall('research_documents', [
      'prompt' => 'What is the funding?',
      'run_in_background' => TRUE,
    ]));
    MockAiProvider::enqueue(new MockResponse(text: 'I started the research.'));
    $this->turn($session, 'Research the funding.');

    $results = $this->results($session, 'research_documents');
    $this->assertCount(1, $results);
    $this->assertSame('pending', $results[0]['status']);
    $taskId = $results[0]['task_id'];
    $this->assertMatchesRegularExpression('/^sub_[0-9a-f]{24}$/', $taskId);

    $row = $this->ledger->load($taskId);
    $this->assertSame('pending', $row['status']);
    $this->assertSame('background', $row['mode']);
    $this->assertSame('none', $row['delivery_state']);
    $this->assertTrue(MockAiProvider::isEmpty(), 'The researcher did not run.');

    $dispatcher = $this->container->get(SubagentDispatcher::class);
    $this->assertSame(1, $dispatcher->dispatchSession((int) $session->id()));
    $this->assertSame(0, $dispatcher->dispatchSession((int) $session->id()));
    $queue = $this->container->get('queue')->get(SubagentDispatcher::QUEUE);
    $this->assertSame(1, $queue->numberOfItems());

    MockAiProvider::enqueue($this->toolCall('research_read_document', ['document_id' => $id, 'page' => 1]));
    MockAiProvider::enqueue(new MockResponse(text: sprintf('Funding is 5M [doc:%d p.1].', $id)));
    $item = $queue->claimItem();
    $this->worker()->processItem($item->data);
    $queue->deleteItem($item);

    $row = $this->ledger->load($taskId);
    $this->assertSame('completed', $row['status']);
    $this->assertSame('waiting', $row['delivery_state']);
    $this->assertNull($row['claimed_by']);
    $this->assertSame(1, (int) $row['attempts']);
    $this->assertArrayHasKey('report', $row['result']);
    $this->assertStringContainsString('Funding is 5M', $row['result']['report']);
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that only one of two workers wins a task.
   */
  public function testSecondClaimLoses(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');

    $first = $this->ledger->claim($taskId, 'worker-1', 300);
    $this->assertSame('running', $first['status']);
    $this->assertSame('worker-1', $first['claimed_by']);
    $this->assertSame(1, (int) $first['attempts']);

    $this->assertNull($this->ledger->claim($taskId, 'worker-2', 300));
    $this->assertSame('worker-1', $this->ledger->load($taskId)['claimed_by']);
    $this->assertFalse($this->ledger->finishClaimed($taskId, 'worker-2', 'completed', []));
    $this->assertTrue($this->ledger->finishClaimed($taskId, 'worker-1', 'completed', ['ok' => TRUE]));
    $this->assertNull($this->ledger->claim($taskId, 'worker-2', 300));
  }

  /**
   * Tests that a claim whose lease lapsed is taken over.
   */
  public function testExpiredClaimIsReclaimed(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $this->ledger->claim($taskId, 'worker-1', 300);

    $this->expireLease($taskId);
    $this->assertContains($taskId, $this->ledger->stale(time()));

    $row = $this->ledger->claim($taskId, 'worker-2', 300);
    $this->assertSame('worker-2', $row['claimed_by']);
    $this->assertSame(2, (int) $row['attempts']);
    $this->assertFalse($this->ledger->renew($taskId, 'worker-1', 300));
    $this->assertTrue($this->ledger->renew($taskId, 'worker-2', 300));
  }

  /**
   * Tests that a task that was claimed three times fails on the fourth claim.
   */
  public function testFourthClaimFailsTheTask(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');

    for ($attempt = 1; $attempt <= 3; $attempt++) {
      $this->assertNotNull($this->ledger->claim($taskId, 'worker-' . $attempt, 300));
      $this->expireLease($taskId);
    }

    $this->assertNull($this->ledger->claim($taskId, 'worker-4', 300));
    $row = $this->ledger->load($taskId);
    $this->assertSame('failed', $row['status']);
    $this->assertSame('waiting', $row['delivery_state']);
    $this->assertSame(['error' => 'The task failed after 3 attempts.'], $row['result']);
    $this->assertSame(3, (int) $row['attempts']);
  }

  /**
   * Tests that a background draft writes one version, and only once.
   */
  public function testBackgroundDraftWritesOneVersion(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));

    MockAiProvider::enqueue($this->toolCall('drafting_draft_content', [
      'brief' => 'Draft it.',
      'run_in_background' => 'true',
    ]));
    MockAiProvider::enqueue(new MockResponse(text: 'The draft is being written.'));
    $this->turn($session, 'Draft it, in the background.');

    $this->assertSame([], $session->listDrafts());
    $this->assertSame(1, $this->container->get(SubagentDispatcher::class)->dispatchSession((int) $session->id()));

    MockAiProvider::enqueue(new MockResponse(text: self::MAIN_FIELDS));
    MockAiProvider::enqueue(new MockResponse(text: self::PARAGRAPHS));
    $queue = $this->container->get('queue')->get(SubagentDispatcher::QUEUE);
    $item = $queue->claimItem();
    $this->worker()->processItem($item->data);

    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'));
    $row = $this->ledger->load($item->data['task_id']);
    $this->assertSame('completed', $row['status']);
    $this->assertSame(1, $row['result']['version']);
    $this->assertTrue(MockAiProvider::isEmpty());

    // The same item again finds a task nobody can claim.
    $this->worker()->processItem($item->data);
    $this->assertSame(['Draft 1'], array_column($session->listDrafts(), 'name'));
    $this->assertSame(1, (int) $this->ledger->load($item->data['task_id'])['attempts']);
  }

  /**
   * Tests that a session runs at most three background tasks at once.
   */
  public function testAtMostThreeBackgroundTasks(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));

    foreach (['a', 'b', 'c'] as $letter) {
      $this->recordBackground($session, 'sub_' . str_repeat($letter, 24));
    }
    $this->assertSame(3, $this->ledger->activeBackground((int) $session->id()));

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'One more.', 'run_in_background' => TRUE]));
    MockAiProvider::enqueue(new MockResponse(text: 'Too many tasks.'));
    $this->turn($session, 'One more.');

    $result = $this->results($session, 'research_documents')[0];
    $this->assertStringContainsString('At most 3 background tasks can run at once in this session.', json_encode($result));
    $this->assertCount(3, $this->ledger->forSession((int) $session->id()));
    $this->assertTrue(MockAiProvider::isEmpty());

    // A finished task frees a place.
    $this->ledger->claim('sub_' . str_repeat('a', 24), 'worker-1', 300);
    $this->ledger->finishClaimed('sub_' . str_repeat('a', 24), 'worker-1', 'completed', []);
    $this->assertSame(2, $this->ledger->activeBackground((int) $session->id()));
  }

  /**
   * Tests that a user runs at most five background tasks across sessions.
   */
  public function testAtMostFiveBackgroundTasksPerUser(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $first = $this->createSession($owner);
    $second = $this->createSession($owner);
    foreach (['a', 'b', 'c'] as $letter) {
      $this->recordBackground($first, 'sub_' . str_repeat($letter, 24));
    }
    foreach (['d', 'e'] as $letter) {
      $this->recordBackground($second, 'sub_' . str_repeat($letter, 24));
    }
    $this->assertSame(5, $this->ledger->activeBackgroundForUser((int) $owner->id()));
    $this->assertSame(0, $this->ledger->activeBackgroundForUser(NULL));

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'One more.', 'run_in_background' => TRUE]));
    MockAiProvider::enqueue(new MockResponse(text: 'Too many tasks.'));
    $this->turn($second, 'One more.');

    $result = $this->results($second, 'research_documents')[0];
    $this->assertStringContainsString('You already have 5 background tasks running', json_encode($result));
    $this->assertCount(2, $this->ledger->forSession((int) $second->id()));
  }

  /**
   * Tests that the site runs at most twenty background tasks.
   */
  public function testAtMostTwentyBackgroundTasksOnTheSite(): void {
    $other = $this->createUser(['use oe ai assistant']);
    for ($i = 0; $i < 20; $i++) {
      $session = $this->createSession($other);
      $this->ledger->record(sprintf('sub_%024d', $i), (int) $session->id(), 'research_documents', 'researcher', 'call_' . $i, $session->editorialThread(), (int) $other->id() + $i, ['prompt' => 'Find it.'], TRUE);
    }
    $this->assertSame(20, $this->ledger->activeBackgroundTotal());

    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'One more.', 'run_in_background' => TRUE]));
    MockAiProvider::enqueue(new MockResponse(text: 'The site is full.'));
    $this->turn($session, 'One more.');

    $result = $this->results($session, 'research_documents')[0];
    $this->assertStringContainsString('The site is running as many background tasks as it allows.', json_encode($result));
    $this->assertSame([], $this->ledger->forSession((int) $session->id()));
  }

  /**
   * Tests that a queued task is only taken for lost after the lease.
   */
  public function testDispatchedPendingTaskIsStaleAfterTheLease(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $this->ledger->markDispatched($taskId);
    $now = time();
    $this->container->get('database')->update(SubagentTaskLedger::TABLE)
      ->fields(['dispatched' => $now - 60, 'changed' => $now - 60])
      ->condition('task_id', $taskId)
      ->execute();
    $this->assertNotContains($taskId, $this->ledger->stale($now));

    $this->container->get('database')->update(SubagentTaskLedger::TABLE)
      ->fields(['dispatched' => $now - 301])
      ->condition('task_id', $taskId)
      ->execute();
    $this->assertContains($taskId, $this->ledger->stale($now));
  }

  /**
   * Tests that a task of a blocked user does not run.
   */
  public function testBlockedUserTaskDoesNotRun(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $session = $this->createSession($owner);
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $owner->block()->save();

    MockAiProvider::enqueue(new MockResponse(text: 'Should not be read.'));
    $this->worker()->processItem(['task_id' => $taskId]);

    $row = $this->ledger->load($taskId);
    $this->assertSame('failed', $row['status']);
    $this->assertSame('none', $row['delivery_state']);
    $this->assertSame(['error' => 'The user of the task can no longer run it.'], $row['result']);
    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Tests that a paused worker suspends the queue and keeps the task.
   */
  public function testPausedWorkerSuspends(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $this->container->get('state')->set('oe_ai_assistant.subagent_worker_paused', TRUE);

    try {
      $this->worker()->processItem(['task_id' => $taskId]);
      $this->fail('The paused worker did not suspend the queue.');
    }
    catch (SuspendQueueException) {
    }
    $this->assertSame('pending', $this->ledger->load($taskId)['status']);
    $this->assertSame(0, (int) $this->ledger->load($taskId)['attempts']);

    $this->container->get('state')->delete('oe_ai_assistant.subagent_worker_paused');
    MockAiProvider::enqueue(new MockResponse(text: 'Nothing found.'));
    $this->worker()->processItem(['task_id' => $taskId]);
    $this->assertSame('completed', $this->ledger->load($taskId)['status']);
  }

  /**
   * Tests that a call without the flag still runs inline.
   */
  public function testForegroundCallIsUnchanged(): void {
    $session = $this->createSession($this->createUser(['use oe ai assistant']));
    $this->createExtractedDocument($session, 'Annual report', 'The budget grew.');

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'Find the budget.']));
    MockAiProvider::enqueue(new MockResponse(text: 'The budget grew.'));
    MockAiProvider::enqueue(new MockResponse(text: 'It grew.'));
    $this->turn($session, 'Find the budget.');

    $result = $this->results($session, 'research_documents')[0];
    $this->assertSame('completed', $result['status']);
    $row = $this->ledger->load($result['task_id']);
    $this->assertSame('foreground', $row['mode']);
    $this->assertSame('completed', $row['status']);
    $this->assertSame([], $this->ledger->undispatched((int) $session->id()));
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Records a pending background task of the research tool.
   */
  private function recordBackground(AiEditorialSessionInterface $session, string $taskId): string {
    $this->ledger->record($taskId, (int) $session->id(), 'research_documents', 'researcher', 'call_' . $taskId, $session->editorialThread(), (int) $session->getOwnerId(), ['prompt' => 'Find the budget.'], TRUE);

    return $taskId;
  }

  /**
   * Lets the lease of a claimed task lapse.
   */
  private function expireLease(string $taskId): void {
    $this->container->get('database')->update(SubagentTaskLedger::TABLE)
      ->fields(['lease_expires' => time() - 10])
      ->condition('task_id', $taskId)
      ->execute();
  }

  /**
   * Returns the queue worker plugin.
   */
  private function worker(): object {
    return $this->container->get('plugin.manager.queue_worker')->createInstance('oe_ai_assistant_subagent_task');
  }

  /**
   * Returns the results of the calls of one tool of the editorial agent.
   */
  private function results(AiEditorialSessionInterface $session, string $tool): array {
    $results = array_filter(
      $session->conversationToolResults(),
      static fn (array $result): bool => $result['name'] === $tool,
    );

    return array_column(array_values($results), 'result');
  }

  /**
   * Runs one turn of the editorial agent.
   */
  private function turn(AiEditorialSessionInterface $session, string $message): void {
    // The tools record the current user as the user of the tasks.
    $this->container->get('current_user')->setAccount($session->getOwner());
    $this->container->get(NeuronAgentManagerInterface::class)
      ->createAgent('editorial', ['session' => $session])
      ->chat(new UserMessage($message), TRUE);
  }

  /**
   * Builds a model reply that calls one tool.
   */
  private function toolCall(string $tool, array $arguments, string $callId = 'call_1'): MockResponse {
    return new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => ['name' => $tool, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)],
        ],
      ],
    );
  }

}
