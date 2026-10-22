<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Kernel tests for EditorialMessageStore, through the real append() path.
 *
 * @group oe_ai_assistant
 */
class EditorialMessageStoreTest extends AiEditorialSessionKernelTestBase {

  /**
   * Returns the site's message store, which must be this module's subclass.
   */
  private function store(): EditorialMessageStore {
    $store = $this->container->get(MessageStoreInterface::class);
    $this->assertInstanceOf(EditorialMessageStore::class, $store);

    return $store;
  }

  /**
   * Returns the neuron_message rows of a thread, oldest first.
   */
  private function rowsOf(string $threadId): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('neuron_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('thread_id', $threadId)
      ->sort('id')
      ->execute();

    return $ids === [] ? [] : array_values($storage->loadMultiple($ids));
  }

  /**
   * A thread this module composes is stamped with the session it names.
   */
  public function testAppendStampsTheSessionOnAnEditorialThread(): void {
    $session = $this->createSession($this->createUser());
    $threadId = 'session_' . $session->id() . '.agent_drafting';

    $this->store()->append($threadId, new UserMessage('Draft the intro.'));

    $rows = $this->rowsOf($threadId);
    $this->assertCount(1, $rows);
    $this->assertSame('editorial_session', $rows[0]->bundle());
    $this->assertSame((int) $session->id(), (int) $rows[0]->get('oe_ai_session')->target_id);
  }

  /**
   * A sub-agent run's own thread is stamped the same way as the editor's.
   */
  public function testAppendStampsTheSessionOnSubAgentThread(): void {
    $session = $this->createSession($this->createUser());
    $threadId = 'session_' . $session->id() . '.agent_field_group.run_a1b2c3d4.group_main_fields';

    $this->store()->append($threadId, new AssistantMessage('{"title": "Draft"}'));

    $rows = $this->rowsOf($threadId);
    $this->assertCount(1, $rows);
    $this->assertSame('editorial_session', $rows[0]->bundle());
    $this->assertSame((int) $session->id(), (int) $rows[0]->get('oe_ai_session')->target_id);
  }

  /**
   * A thread this module did not compose is written as the plain bundle.
   */
  public function testAppendOfAnotherModulesThreadWritesThePlainBundle(): void {
    $threadId = 'some_other_module.a_thread_id';

    $this->store()->append($threadId, new UserMessage('Summarise this document.'));

    $rows = $this->rowsOf($threadId);
    $this->assertCount(1, $rows);
    $this->assertNotSame('editorial_session', $rows[0]->bundle());
  }

  /**
   * Every message of a multi-turn conversation carries the session stamp.
   */
  public function testAppendStampsEveryMessageOfConversation(): void {
    $session = $this->createSession($this->createUser());
    $threadId = 'session_' . $session->id() . '.agent_drafting';

    $store = $this->store();
    $store->append($threadId, new UserMessage('Draft the intro.'));
    $store->append($threadId, new AssistantMessage('Sure, give me a moment.'));

    $rows = $this->rowsOf($threadId);
    $this->assertCount(2, $rows);
    foreach ($rows as $row) {
      $this->assertSame((int) $session->id(), (int) $row->get('oe_ai_session')->target_id);
    }
  }

}
