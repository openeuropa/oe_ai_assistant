<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\EntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\user\Entity\User;

/**
 * Kernel tests for the ai_conversation_message storage handler.
 *
 * Covers the host-scoped queries used to list a conversation: the flat
 * top-level transcript, the full nested tree, and host-wide deletion.
 *
 * @group oe_ai_assistant
 */
class AiConversationMessageStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Drupal core.
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    'serialization',
    'image',
    'link',
    'taxonomy',
    // Contrib.
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    // This project.
    'oe_ai_assistant',
  ];

  /**
   * The conversation message storage handler.
   *
   * @var \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface
   */
  protected $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_conversation_message');

    $this->storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_conversation_message');
  }

  /**
   * Tests the transcript query and host-wide deletion.
   */
  public function testLoadTranscriptAndDeleteForHost(): void {
    $host = User::create(['name' => 'session-host']);
    $host->save();
    $other = User::create(['name' => 'other-host']);
    $other->save();

    // Three top-level, non-error turns in the intended order.
    $this->createMessage($host, AiConversationMessageInterface::ROLE_USER, 'Draft a news article.');
    $assistant = $this->createMessage($host, AiConversationMessageInterface::ROLE_ASSISTANT, 'Here is the plan.');
    $this->createMessage($host, AiConversationMessageInterface::ROLE_TOOL, 'Tool result.');

    // A sub-agent child must not surface in the top-level transcript.
    $this->createMessage($host, AiConversationMessageInterface::ROLE_ASSISTANT, 'Sub-agent turn.', (int) $assistant->id());

    // A top-level error row must be excluded from the transcript.
    $error = $this->createMessage($host, AiConversationMessageInterface::ROLE_ERROR, 'Boom.');

    // Another host's turn must not leak into the transcript.
    $this->createMessage($other, AiConversationMessageInterface::ROLE_USER, 'Other host turn.');

    // The transcript is the host's non-error top-level rows, as entities.
    $transcript = $this->storage->loadTranscript($host);
    $this->assertContainsOnlyInstancesOf(AiConversationMessageInterface::class, $transcript);
    $this->assertSame(
      ['user', 'assistant', 'tool'],
      array_values(array_map(static fn ($m) => $m->getRole(), $transcript))
    );
    $this->assertSame('Draft a news article.', array_values($transcript)[0]->get('content')->value);

    // deleteForHost removes every row of the host, error rows included, and
    // leaves other hosts untouched.
    $this->storage->deleteForHost($host);
    $this->assertNull($this->storage->loadUnchanged((int) $error->id()));
    $this->assertCount(1, $this->storage->loadTranscript($other));
  }

  /**
   * Tests that loadTree nests children under parents and keeps error rows.
   */
  public function testLoadTree(): void {
    $host = User::create(['name' => 'session-host']);
    $host->save();

    $root = $this->createMessage($host, AiConversationMessageInterface::ROLE_USER, 'Root turn.');
    $this->createMessage($host, AiConversationMessageInterface::ROLE_ASSISTANT, 'Child A.', (int) $root->id());
    $childB = $this->createMessage($host, AiConversationMessageInterface::ROLE_ASSISTANT, 'Child B.', (int) $root->id());
    $this->createMessage($host, AiConversationMessageInterface::ROLE_TOOL, 'Grandchild.', (int) $childB->id());
    // A top-level error row is part of the debug tree.
    $this->createMessage($host, AiConversationMessageInterface::ROLE_ERROR, 'Boom.');

    $tree = $this->storage->loadTree($host);

    // Two roots: the user turn and the error row.
    $this->assertCount(2, $tree);
    $this->assertSame('Root turn.', $tree[0]['message']->get('content')->value);
    $this->assertSame('Boom.', $tree[1]['message']->get('content')->value);

    // The user turn has two children, and Child B nests the grandchild.
    $this->assertCount(2, $tree[0]['children']);
    $this->assertSame('Child B.', $tree[0]['children'][1]['message']->get('content')->value);
    $this->assertCount(1, $tree[0]['children'][1]['children']);
    $this->assertSame('Grandchild.', $tree[0]['children'][1]['children'][0]['message']->get('content')->value);
  }

  /**
   * Creates and saves a conversation message hosted by the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The host entity.
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message content.
   * @param int|null $parent
   *   The message parent ID.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   An AI conversation message entity.
   */
  private function createMessage(EntityInterface $host, string $role, string $content, ?int $parent = NULL): AiConversationMessageInterface {
    $message = AiConversationMessage::create([
      'host_entity_type' => $host->getEntityTypeId(),
      'host_entity_id' => (int) $host->id(),
      'parent' => $parent,
      'role' => $role,
      'content' => $content,
    ]);
    $message->save();
    return $message;
  }

}
