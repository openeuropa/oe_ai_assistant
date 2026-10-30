<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Kernel tests for EditorialMessageStore, through the real append() path.
 *
 * The store reads the session off the agent plugin the manager bound to it,
 * so every case here binds a real plugin rather than naming a thread.
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
   * Returns the store bound to a run of one agent, on the given session.
   */
  private function storeFor(string $pluginId, AiEditorialSessionInterface $session): EditorialMessageStore {
    $plugin = $this->container->get(NeuronAgentManagerInterface::class)->createInstance($pluginId);
    $plugin->setContextValue('session', $session);

    return $this->store()->forAgentPlugin($plugin);
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
   * A run of an editorial agent stamps the session on its rows.
   */
  public function testAppendStampsTheSessionOfTheRun(): void {
    $session = $this->createSession($this->createUser());

    $this->storeFor('drafting', $session)->append('thread-1', new UserMessage('Draft the intro.'));

    $rows = $this->rowsOf('thread-1');
    $this->assertCount(1, $rows);
    $this->assertSame('editorial_session', $rows[0]->bundle());
    $this->assertSame((int) $session->id(), (int) $rows[0]->get('oe_ai_session')->target_id);
    $this->assertSame('drafting', $rows[0]->get('agent_id')->value);
  }

  /**
   * A sub-agent run of the same session is stamped the same way.
   */
  public function testSubAgentRunCarriesTheSameSession(): void {
    $session = $this->createSession($this->createUser());

    $this->storeFor('field_group', $session)->append('thread-2', new AssistantMessage('{"title": "Draft"}'));

    $rows = $this->rowsOf('thread-2');
    $this->assertCount(1, $rows);
    $this->assertSame('editorial_session', $rows[0]->bundle());
    $this->assertSame((int) $session->id(), (int) $rows[0]->get('oe_ai_session')->target_id);
    $this->assertSame('field_group', $rows[0]->get('agent_id')->value);
  }

  /**
   * A run with no session behind it is written as the plain message type.
   */
  public function testRunWithoutSessionWritesThePlainBundle(): void {
    $this->store()->append('thread-3', new UserMessage('Summarise this document.'));

    $rows = $this->rowsOf('thread-3');
    $this->assertCount(1, $rows);
    $this->assertSame('default', $rows[0]->bundle());
    $this->assertFalse($rows[0]->hasField('oe_ai_session'));
  }

  /**
   * Every message of a multi-turn conversation carries the session stamp.
   */
  public function testAppendStampsEveryMessageOfConversation(): void {
    $session = $this->createSession($this->createUser());
    $store = $this->storeFor('drafting', $session);

    $store->append('thread-4', new UserMessage('Draft the intro.'));
    $store->append('thread-4', new AssistantMessage('Sure, give me a moment.'));

    $rows = $this->rowsOf('thread-4');
    $this->assertCount(2, $rows);
    foreach ($rows as $row) {
      $this->assertSame((int) $session->id(), (int) $row->get('oe_ai_session')->target_id);
    }
  }

}
