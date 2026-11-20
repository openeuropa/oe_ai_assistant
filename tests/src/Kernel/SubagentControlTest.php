<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentDelivery;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentDispatcher;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentRunner;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests checking and stopping the background tasks of a session.
 */
#[Group('oe_ai_assistant')]
class SubagentControlTest extends AiEditorialSessionKernelTestBase {

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
   * Tests that a pending task that was stopped never runs.
   */
  public function testStoppedPendingTaskNeverRuns(): void {
    $session = $this->createSession($this->createUser());
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');

    $this->assertSame('stopped', $this->ledger->stop($taskId, (int) $session->id()));
    $this->assertSame('finished', $this->ledger->stop($taskId, (int) $session->id()));

    MockAiProvider::enqueue(new MockResponse(text: 'Should not be read.'));
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('oe_ai_assistant_subagent_task')
      ->processItem(['task_id' => $taskId]);

    $this->assertSame([], MockAiProvider::getCallLog());
    $row = $this->ledger->load($taskId);
    $this->assertSame('stopped', $row['status']);
    $this->assertSame('none', $row['delivery_state']);
    $this->assertSame(0, (int) $row['attempts']);
  }

  /**
   * Tests that a running task stops at its next check, and delivers nothing.
   */
  public function testRunningTaskStopsAtNextCheck(): void {
    $session = $this->createSession($this->createUser());
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $ledger = $this->ledger;
    $sessionId = (int) $session->id();

    // Each reading of the clock moves it 3 s on, past the check interval. The
    // stop comes in once the worker holds the task.
    $clock = new class ($ledger, $taskId, $sessionId) implements TimeInterface {

      /**
       * The time the clock shows.
       */
      private int $now = 1000000;

      /**
       * Whether the stop was requested.
       */
      private bool $stopped = FALSE;

      public function __construct(
        private readonly SubagentTaskLedger $ledger,
        private readonly string $taskId,
        private readonly int $sessionId,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return (float) $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        $this->now += 3;
        if (!$this->stopped && ($this->ledger->load($this->taskId)['status'] ?? '') === 'running') {
          $this->stopped = TRUE;
          $this->ledger->stop($this->taskId, $this->sessionId);
        }

        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->getCurrentTime();
      }

    };
    $runner = new SubagentRunner(
      $this->ledger,
      $this->container->get(SubagentDispatcher::class),
      $this->container->get('entity_type.manager'),
      $this->container->get(AccountSwitcherInterface::class),
      $this->container->get(NeuronToolManagerInterface::class),
      $clock,
      new NullLogger(),
    );

    MockAiProvider::enqueue(new MockResponse(text: 'The budget grew.'));
    $runner->run($taskId);

    $row = $this->ledger->load($taskId);
    $this->assertSame('stopped', $row['status']);
    $this->assertSame('none', $row['delivery_state']);
    $this->assertSame(['note' => 'Stopped by the editor.'], $row['result']);
    $this->assertNull($row['claimed_by']);
    $this->assertFalse($this->ledger->hasWaiting($sessionId));
    $this->assertTrue($this->ledger->stopRequested($taskId));
  }

  /**
   * Tests that the check tool hands over a waiting result once.
   */
  public function testCheckTasksConsumesWaitingResult(): void {
    $session = $this->createSession($this->createUser());
    $taskId = $this->recordBackground($session, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');
    $this->ledger->claim($taskId, 'worker-1', 300);
    $this->ledger->finishClaimed($taskId, 'worker-1', 'completed', ['report' => 'Funding is 5M.']);
    $this->assertTrue($this->ledger->hasWaiting((int) $session->id()));

    MockAiProvider::enqueue($this->toolCall('subagent_check_tasks', []));
    MockAiProvider::enqueue(new MockResponse(text: 'It is done.'));
    $this->turn($session, 'How are the tasks?');
    MockAiProvider::enqueue($this->toolCall('subagent_check_tasks', [], 'call_2'));
    MockAiProvider::enqueue(new MockResponse(text: 'Still done.'));
    $this->turn($session, 'And now?');

    $results = array_column(array_filter(
      $session->conversationToolResults(),
      static fn (array $result): bool => $result['name'] === 'subagent_check_tasks',
    ), 'result');
    $first = $this->decodeResult(reset($results));
    $this->assertSame($taskId, $first['task_id']);
    $this->assertSame('consumed', $first['delivery_state']);
    $this->assertSame(['report' => 'Funding is 5M.'], $first['result']);
    $second = $this->decodeResult(end($results));
    $this->assertArrayNotHasKey('result', $second);
    $this->assertSame('consumed', $this->ledger->load($taskId)['delivery_state']);

    $this->assertSame(
      SubagentDelivery::NOTHING,
      $this->container->get(SubagentDelivery::class)->deliver($session),
    );
  }

  /**
   * Tests that a task of no session or of another one cannot be stopped.
   */
  public function testStopUnknownTask(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $other = $this->createSession($owner);
    $taskId = $this->recordBackground($other, 'sub_aaaaaaaaaaaaaaaaaaaaaaaa');

    $this->assertSame('unknown', $this->ledger->stop('sub_missing', (int) $session->id()));
    $this->assertSame('unknown', $this->ledger->stop($taskId, (int) $session->id()));
    $this->assertSame('pending', $this->ledger->load($taskId)['status']);

    $this->ledger->claim($taskId, 'worker-1', 300);
    $this->assertSame('stopping', $this->ledger->stop($taskId, (int) $other->id()));
    $this->assertTrue($this->ledger->stopRequested($taskId));
  }

  /**
   * Decodes the result of the check tool as the model read it.
   */
  private function decodeResult(mixed $result): array {
    $tasks = is_array($result) ? ($result['tasks'] ?? $result) : (json_decode((string) $result, TRUE)['tasks'] ?? []);

    return $tasks[0] ?? [];
  }

  /**
   * Records a pending background task of the research tool.
   */
  private function recordBackground(AiEditorialSessionInterface $session, string $taskId): string {
    $this->ledger->record($taskId, (int) $session->id(), 'research_documents', 'researcher', 'call_' . $taskId, $session->editorialThread(), (int) $session->getOwnerId(), ['prompt' => 'Find the budget.'], TRUE);

    return $taskId;
  }

  /**
   * Runs one turn of the editorial agent.
   */
  private function turn(AiEditorialSessionInterface $session, string $message): void {
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
