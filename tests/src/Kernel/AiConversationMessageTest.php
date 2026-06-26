<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException;

/**
 * Kernel tests for the ai_conversation_message entity.
 *
 * Covers persistence of the base fields, the generic owner back-reference used
 * to group a conversation, and the parent self-reference used to build the
 * sub-agent tree.
 *
 * @group oe_ai_assistant
 */
class AiConversationMessageTest extends KernelTestBase {

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
   * The conversation message storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
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
   * Tests that a message round-trips through storage with its fields intact.
   */
  public function testCreateLoadAndAccessors(): void {
    $message = AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 42,
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
      'agent_id' => 'orchestrator',
      'content' => 'Here is the plan.',
      'provider' => 'mistral',
      'model' => 'mistral-large-latest',
    ]);
    $message->setToolCalls([['name' => 'draft_content']]);
    $message->setTokenUsage(['input' => 10, 'output' => 5, 'total' => 15]);
    $message->save();

    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $loaded */
    $loaded = $this->storage->loadUnchanged($message->id());

    $this->assertSame('ai_editorial_session', $loaded->getOwnerEntityType());
    $this->assertSame(42, $loaded->getOwnerEntityId());
    $this->assertSame(AiConversationMessageInterface::ROLE_ASSISTANT, $loaded->getRole());
    $this->assertNull($loaded->getParentId());
    $this->assertSame('orchestrator', $loaded->get('agent_id')->value);
    $this->assertSame('mistral', $loaded->get('provider')->value);

    // The typed getters decode the stored JSON back to arrays.
    $this->assertSame([['name' => 'draft_content']], $loaded->getToolCalls());
    $this->assertSame(['input' => 10, 'output' => 5, 'total' => 15], $loaded->getTokenUsage());
    $this->assertSame([], $loaded->getMetadata());

    // The field stores JSON, not the array.
    $this->assertSame('{"input":10,"output":5,"total":15}', $loaded->get('token_usage')->value);

    // The send time is stamped on save when the caller did not set it.
    $this->assertNotEmpty($loaded->get('created')->value);
  }

  /**
   * Tests that saving a malformed JSON value (set directly) is rejected.
   */
  public function testSavingInvalidJsonThrows(): void {
    $message = AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 42,
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
    ]);
    // Bypass the typed setter to plant malformed JSON.
    $message->set('tool_calls', 'not valid json');

    try {
      $message->save();
      $this->fail('Expected the save to be blocked.');
    }
    catch (EntityStorageException $e) {
      // SqlContentEntityStorage wraps preSave exceptions in a base
      // EntityStorageException; our typed exception is the wrapped previous.
      $this->assertInstanceOf(InvalidJsonFieldException::class, $e->getPrevious() ?? $e);
    }
  }

  /**
   * Tests that a typed getter on a malformed value throws.
   */
  public function testGetterOnInvalidJsonThrows(): void {
    $message = AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 42,
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
    ]);
    $message->set('metadata', '{broken');

    $this->expectException(InvalidJsonFieldException::class);
    $message->getMetadata();
  }

  /**
   * Tests that a value that cannot be encoded to JSON is rejected by the setter.
   */
  public function testSetterRejectsUnencodableValue(): void {
    $message = AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 42,
      'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
    ]);

    $this->expectException(InvalidJsonFieldException::class);
    // Invalid UTF-8 cannot be encoded to JSON.
    $message->setMetadata(['bad' => "\xB1\x31"]);
  }

  /**
   * Tests grouping by owner and the parent self-reference tree.
   */
  public function testOwnerGroupingAndParentTree(): void {
    $parent = AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 42,
      'role' => AiConversationMessageInterface::ROLE_USER,
      'content' => 'Draft a news article about X.',
    ]);
    $parent->save();
    $parent_id = (int) $parent->id();

    foreach (['title-agent', 'body-agent'] as $agent_id) {
      AiConversationMessage::create([
        'owner_entity_type' => 'ai_editorial_session',
        'owner_entity_id' => 42,
        'parent' => $parent_id,
        'role' => AiConversationMessageInterface::ROLE_ASSISTANT,
        'agent_id' => $agent_id,
        'content' => 'Generated slice.',
      ])->save();
    }

    // A message under a different owner must not leak into the conversation.
    AiConversationMessage::create([
      'owner_entity_type' => 'ai_editorial_session',
      'owner_entity_id' => 99,
      'role' => AiConversationMessageInterface::ROLE_USER,
      'content' => 'Unrelated session.',
    ])->save();

    // The whole conversation is the set of rows sharing the owner.
    $conversation = $this->storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner_entity_type', 'ai_editorial_session')
      ->condition('owner_entity_id', 42)
      ->sort('created')
      ->sort('id')
      ->execute();
    $this->assertCount(3, $conversation);

    // The top-level transcript is the owner rows with no parent.
    $top_level = $this->storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner_entity_id', 42)
      ->condition('parent', NULL, 'IS NULL')
      ->execute();
    $this->assertSame([(string) $parent_id], array_values($top_level));

    // A message's sub-agent calls are its children by parent.
    $children = $this->storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent', $parent_id)
      ->execute();
    $this->assertCount(2, $children);
  }

}
