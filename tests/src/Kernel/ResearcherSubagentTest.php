<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\Subagent\SubagentTaskLedger;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the research tool, its researcher subagent and the task ledger.
 */
#[Group('oe_ai_assistant')]
class ResearcherSubagentTest extends AiEditorialSessionKernelTestBase {

  use ResearchDocumentTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', [
        'chat_with_tools' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model'],
      ])
      ->save();
    MockAiProvider::reset();
  }

  /**
   * Tests that a report cites pages, and only the pages read are verified.
   */
  public function testReportsWithVerifiedCitations(): void {
    $session = $this->createSession($this->createUser());
    $id = $this->createExtractedDocument($session, 'Annual report', 'Funding is 5M euros. Staff is 3 people.');

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'What is the funding?']));
    MockAiProvider::enqueue($this->toolCall('research_search_documents', ['query' => 'funding']));
    MockAiProvider::enqueue($this->toolCall('research_read_document', ['document_id' => $id, 'page' => 1]));
    MockAiProvider::enqueue(new MockResponse(text: sprintf('Funding is 5M [doc:%1$d p.1]. Staff is 3 [doc:%1$d p.9].', $id)));
    MockAiProvider::enqueue(new MockResponse(text: 'The funding is 5M.'));

    $this->turn($session, 'How much funding is there?');

    $results = $this->researchResults($session);
    $this->assertCount(1, $results);
    $result = $results[0];
    $this->assertSame('completed', $result['status']);
    $this->assertSame('researcher', $result['subagent']);
    $this->assertMatchesRegularExpression('/^sub_[0-9a-f]{24}$/', $result['task_id']);
    $this->assertSame([
      ['document_id' => $id, 'page' => 1, 'verified' => TRUE],
      ['document_id' => $id, 'page' => 9, 'verified' => FALSE],
    ], $result['citations']);

    $row = $this->container->get(SubagentTaskLedger::class)->load($result['task_id']);
    $this->assertSame('completed', $row['status']);
    $this->assertSame('researcher', $row['subagent']);
    $this->assertSame('research_documents', $row['tool']);
    $this->assertSame($session->editorialThread(), $row['parent_thread']);

    $thread = 'researcher.session_' . $session->id() . '.' . $result['task_id'];
    $threads = $session->conversationThreads();
    $this->assertArrayHasKey($thread, $threads);
    $this->assertGreaterThanOrEqual(4, count($threads[$thread]));

    // The researcher's words stay out of what the editorial agent and the
    // drafters read.
    $this->assertStringNotContainsString('Staff is 3', json_encode($session->conversationTurns()));
    $this->assertCount(2, $session->conversationTurns());
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that a task asks the editor, and the answer continues the same run.
   */
  public function testAsksAndContinuesOnSameThread(): void {
    $session = $this->createSession($this->createUser());
    $this->createExtractedDocument($session, 'Annual report', 'The budget grew.');

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'Find the budget.']));
    MockAiProvider::enqueue($this->toolCall('research_ask_editor', ['question' => 'Which region?']));
    MockAiProvider::enqueue(new MockResponse(text: 'I need the region.'));
    MockAiProvider::enqueue(new MockResponse(text: 'Which region do you mean?'));
    $this->turn($session, 'Find the budget.');

    $result = $this->researchResults($session)[0];
    $this->assertSame('needs_input', $result['status']);
    $this->assertSame('Which region?', $result['question']);
    $taskId = $result['task_id'];
    $ledger = $this->container->get(SubagentTaskLedger::class);
    $this->assertSame('needs_input', $ledger->load($taskId)['status']);
    $this->assertTrue(MockAiProvider::isEmpty());

    MockAiProvider::enqueue($this->toolCall('research_documents', ['task_id' => $taskId, 'prompt' => 'Flanders'], 'call_2'));
    MockAiProvider::enqueue(new MockResponse(text: 'The budget of Flanders grew.'));
    MockAiProvider::enqueue(new MockResponse(text: 'It grew.'));
    $this->turn($session, 'Flanders');

    $results = $this->researchResults($session);
    $this->assertCount(2, $results);
    $this->assertSame($taskId, $results[1]['task_id']);
    $this->assertSame('completed', $results[1]['status']);
    $row = $ledger->load($taskId);
    $this->assertSame('completed', $row['status']);
    $this->assertSame(1, (int) $row['continuations']);

    $threads = $session->conversationThreads();
    $this->assertCount(1, preg_grep('/^researcher\./', array_keys($threads)));
    $rows = $threads['researcher.session_' . $session->id() . '.' . $taskId];
    $asks = array_merge(...array_map(static fn ($row): array => $row->getToolResults(), $rows));
    $this->assertSame(['research_ask_editor'], array_column($asks, 'name'));
    $history = json_encode(array_map(static fn ($row) => $row->getContent(), $rows));
    $this->assertStringContainsString('The editor answered: Flanders', $history);
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that a task id that no row has is an error, and nothing runs.
   */
  public function testUnknownTaskIdIsAnError(): void {
    $session = $this->createSession($this->createUser());

    MockAiProvider::enqueue($this->toolCall('research_documents', [
      'task_id' => 'sub_000000000000000000000000',
      'prompt' => 'Flanders',
    ]));
    MockAiProvider::enqueue(new MockResponse(text: 'I could not continue that.'));
    $this->turn($session, 'Flanders');

    $result = $this->researchResults($session)[0];
    $this->assertStringContainsString('Unknown task_id', json_encode($result));
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that a task that finished cannot be continued.
   */
  public function testContinuingCompletedTaskIsAnError(): void {
    $session = $this->createSession($this->createUser());
    $this->createExtractedDocument($session, 'Annual report', 'The budget grew.');

    MockAiProvider::enqueue($this->toolCall('research_documents', ['prompt' => 'Find the budget.']));
    MockAiProvider::enqueue(new MockResponse(text: 'The budget grew.'));
    MockAiProvider::enqueue(new MockResponse(text: 'It grew.'));
    $this->turn($session, 'Find the budget.');
    $taskId = $this->researchResults($session)[0]['task_id'];

    MockAiProvider::enqueue($this->toolCall('research_documents', ['task_id' => $taskId, 'prompt' => 'More'], 'call_2'));
    MockAiProvider::enqueue(new MockResponse(text: 'That task is over.'));
    $this->turn($session, 'More');

    $result = $this->researchResults($session)[1];
    $this->assertStringContainsString('is completed and cannot be continued', json_encode($result));
    $this->assertSame(0, (int) $this->container->get(SubagentTaskLedger::class)->load($taskId)['continuations']);
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Returns the results of the research calls of the editorial agent.
   *
   * The researcher's own tool results are rows of the session too, so the
   * results are picked by the name of the tool.
   */
  private function researchResults(AiEditorialSessionInterface $session): array {
    $results = array_filter(
      $session->conversationToolResults(),
      static fn (array $result): bool => $result['name'] === 'research_documents',
    );

    return array_column(array_values($results), 'result');
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
