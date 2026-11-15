<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;

/**
 * Integration tests for saving a draft through the gated agent tool.
 *
 * The agent calls drafting_save_draft, and the call waits for the editor
 * before anything is written.
 */
class DraftingPluginApprovalTest extends DraftingPluginTestBase {

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
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_save',
          'type' => 'function',
          'function' => ['name' => 'drafting_save_draft', 'arguments' => '{"version":"two"}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'I need a draft number.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Save draft two.',
      'sessionId' => $session->id(),
    ]);
    $events = $this->parseSseEvents($result['body']);

    $this->assertContains('finish', array_column($events, 'type'));
    $this->assertNotSame(WorkflowStatus::Suspended, $this->editorialRun($session)?->status);
    $this->assertSame([], $this->newNodeIds());
  }

  /**
   * Creates a logged-in user's session holding "Draft 1".
   */
  private function sessionWithDraft(): AiEditorialSessionInterface {
    $user = $this->createUser([
      'use oe ai assistant',
      'create oe_news content',
    ]);
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
   * @param int $version
   *   The version the model asks to save.
   * @param string $callId
   *   The id of the tool call.
   * @param string|null $reply
   *   The text the model answers with after the tool result, if any.
   *
   * @return array
   *   The parsed SSE events.
   */
  private function askToSave(AiEditorialSessionInterface $session, int $version, string $callId = 'call_save', ?string $reply = NULL): array {
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
      'message' => sprintf('Save draft %d.', $version),
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'], substr($result['body'], 0, 500));

    return $this->parseSseEvents($result['body']);
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
      static fn (array $event): bool => $event['type'] === 'tool-output-available' && ($event['toolCallId'] ?? NULL) === $callId,
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
