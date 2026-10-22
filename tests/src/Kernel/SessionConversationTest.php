<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;

/**
 * Kernel tests for SessionConversation, the reader over neuron_message rows.
 *
 * Fixtures are created directly on the storage, bypassing the message store,
 * since the reader only cares about the oe_ai_session reference.
 *
 * @group oe_ai_assistant
 */
class SessionConversationTest extends AiEditorialSessionKernelTestBase {

  /**
   * The reader under test.
   */
  private function conversation(): SessionConversation {
    return $this->container->get(SessionConversation::class);
  }

  /**
   * Creates one neuron_message row of the editorial_session bundle.
   */
  private function createRow(AiEditorialSessionInterface $session, string $threadId, array $values = []): void {
    static $counter = 0;
    $counter++;

    $storage = $this->container->get('entity_type.manager')->getStorage('neuron_message');
    $storage->create($values + [
      'bundle' => 'editorial_session',
      'oe_ai_session' => (int) $session->id(),
      'thread_id' => $threadId,
      'message_id' => 'msg_' . $counter,
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'agent_id' => 'drafting',
      'role' => 'user',
      'turn' => 1,
      'complete' => TRUE,
      'content' => json_encode([['type' => 'text', 'content' => 'hello', 'meta' => []]]),
      'meta' => json_encode(['type' => 'text']),
    ])->save();
  }

  /**
   * Every row of a session is answered, across its threads.
   */
  public function testRowsAnswersEverySessionRowAcrossThreads(): void {
    $session = $this->createSession($this->createUser());
    $other = $this->createSession($this->createUser());

    $this->createRow($session, 'session_' . $session->id() . '.agent_drafting');
    $this->createRow($session, 'session_' . $session->id() . '.agent_field_group.run_1.group_main_fields');
    $this->createRow($other, 'session_' . $other->id() . '.agent_drafting');

    $rows = $this->conversation()->rows($session);

    $this->assertCount(2, $rows);
  }

  /**
   * A reader asking for one agent gets only that agent's rows.
   */
  public function testRowsFiltersByAgent(): void {
    $session = $this->createSession($this->createUser());

    $this->createRow($session, 'session_' . $session->id() . '.agent_drafting', ['agent_id' => 'drafting']);
    $this->createRow($session, 'session_' . $session->id() . '.agent_field_group.run_1.group_main_fields', [
      'agent_id' => 'field_group',
    ]);

    $rows = $this->conversation()->rows($session, 'field_group');

    $this->assertCount(1, $rows);
    $this->assertSame('field_group', $rows[0]->get('agent_id')->value);
  }

  /**
   * A session's rows are grouped by thread ID, each thread oldest first.
   */
  public function testThreadsGroupsRowsByThreadId(): void {
    $session = $this->createSession($this->createUser());
    $editorThread = 'session_' . $session->id() . '.agent_drafting';
    $runThread = 'session_' . $session->id() . '.agent_field_group.run_1.group_main_fields';

    $this->createRow($session, $editorThread);
    $this->createRow($session, $runThread);
    $this->createRow($session, $runThread);

    $threads = $this->conversation()->threads($session);

    $this->assertSame([$editorThread, $runThread], array_keys($threads));
    $this->assertCount(1, $threads[$editorThread]);
    $this->assertCount(2, $threads[$runThread]);
  }

  /**
   * Every settled tool call of the session is decoded.
   */
  public function testToolResultsDecodesSettledCalls(): void {
    $session = $this->createSession($this->createUser());

    $this->createRow($session, 'session_' . $session->id() . '.agent_drafting', [
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          ['name' => 'draft_group', 'result' => json_encode(['version' => '1.0'])],
        ],
      ]),
    ]);
    // A row that is not a settled tool call contributes nothing.
    $this->createRow($session, 'session_' . $session->id() . '.agent_drafting', [
      'meta' => json_encode(['type' => 'text']),
    ]);

    $results = $this->conversation()->toolResults($session);

    $this->assertSame([
      ['name' => 'draft_group', 'result' => ['version' => '1.0']],
    ], $results);
  }

  /**
   * Deleting a session removes only its own rows, drafter runs included.
   */
  public function testDeleteForRemovesOnlyItsOwnSessionsRows(): void {
    $session = $this->createSession($this->createUser());
    $other = $this->createSession($this->createUser());

    $this->createRow($session, 'session_' . $session->id() . '.agent_drafting');
    $this->createRow($session, 'session_' . $session->id() . '.agent_field_group.run_1.group_main_fields');
    $this->createRow($other, 'session_' . $other->id() . '.agent_drafting');

    $this->conversation()->deleteFor($session);

    $this->assertSame([], $this->conversation()->rows($session));
    $this->assertCount(1, $this->conversation()->rows($other));
  }

}
