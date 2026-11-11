<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;

/**
 * Kernel tests for the conversation a session reads off its message rows.
 *
 * Fixtures are created directly on the storage, bypassing the message store,
 * since the session only cares about the oe_ai_session reference.
 *
 * @group oe_ai_assistant
 */
class AiEditorialSessionConversationTest extends AiEditorialSessionKernelTestBase {

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
      'agent_id' => 'editorial',
      'role' => 'user',
      'turn' => 1,
      'complete' => TRUE,
      'content' => json_encode([['type' => 'text', 'content' => 'hello', 'meta' => []]]),
      'meta' => json_encode(['type' => 'text']),
    ])->save();
  }

  /**
   * The next version comes from the column, not from the stored drafts.
   *
   * Each row of the drafts thread holds a whole draft as JSON, and reading
   * the number out of every one of them parses a large payload per draft.
   * The column already holds it. The row below names version 11 and carries
   * no draft anyone can parse, so it counts here and would not if we read
   * the payloads.
   */
  public function testNextVersionReadsTheVersionColumn(): void {
    $session = $this->createSession($this->createUser());
    assert($session instanceof ContentCreationSessionInterface);

    foreach (['1', '2', '9', '10'] as $version) {
      $this->createRow($session, $session->draftsThread(), [
        'oe_draft_version' => $version,
        'content' => json_encode([
          [
            'type' => 'text',
            'content' => json_encode(['version' => $version, 'fields' => []]),
            'meta' => [],
          ],
        ]),
      ]);
    }
    $this->createRow($session, $session->draftsThread(), [
      'oe_draft_version' => '11',
      'content' => json_encode([['type' => 'text', 'content' => 'not a draft', 'meta' => []]]),
    ]);

    $this->assertSame(12, $session->nextVersion());
  }

  /**
   * A session's rows are grouped by thread ID, and another's are left out.
   */
  public function testConversationThreadsGroupsOnlyItsOwnRows(): void {
    $session = $this->createSession($this->createUser());
    $other = $this->createSession($this->createUser());
    $editorThread = 'editorial.session_' . $session->id();
    $runThread = 'group_drafter.run_1';

    $this->createRow($session, $editorThread);
    $this->createRow($session, $runThread);
    $this->createRow($session, $runThread);
    $this->createRow($other, 'editorial.session_' . $other->id());

    $threads = $session->conversationThreads();

    $this->assertSame([$editorThread, $runThread], array_keys($threads));
    $this->assertCount(1, $threads[$editorThread]);
    $this->assertCount(2, $threads[$runThread]);
  }

  /**
   * Every settled tool call of the session is decoded.
   */
  public function testConversationToolResultsDecodesSettledCalls(): void {
    $session = $this->createSession($this->createUser());

    $this->createRow($session, 'editorial.session_' . $session->id(), [
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          ['name' => 'drafting_draft_content', 'result' => json_encode(['version' => '1.0'])],
        ],
      ]),
    ]);
    // A row that is not a settled tool call contributes nothing.
    $this->createRow($session, 'editorial.session_' . $session->id(), [
      'meta' => json_encode(['type' => 'text']),
    ]);

    $this->assertSame([
      ['name' => 'drafting_draft_content', 'result' => ['version' => '1.0']],
    ], $session->conversationToolResults());
  }

  /**
   * Deleting a session's conversation leaves another session's rows alone.
   */
  public function testDeleteConversationRemovesOnlyItsOwnRows(): void {
    $session = $this->createSession($this->createUser());
    $other = $this->createSession($this->createUser());

    $this->createRow($session, 'editorial.session_' . $session->id());
    $this->createRow($session, 'group_drafter.run_1');
    $this->createRow($other, 'editorial.session_' . $other->id());

    $session->deleteConversation();

    $this->assertSame([], $session->conversationThreads());
    $this->assertCount(1, $other->conversationThreads());
  }

}
