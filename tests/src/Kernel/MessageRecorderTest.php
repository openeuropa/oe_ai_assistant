<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\Entity\User;

/**
 * Kernel tests for the message recorder write side.
 *
 * Covers recording user, assistant, tool, and error turns as
 * ai_conversation_message rows hosted by an entity, with token usage
 * normalized from the ChatOutput and the parent linkage for sub-agent turns.
 *
 * @group oe_ai_assistant
 */
class MessageRecorderTest extends KernelTestBase {

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
   * The message recorder.
   *
   * @var \Drupal\oe_ai_assistant\Service\MessageRecorderInterface
   */
  protected MessageRecorderInterface $recorder;

  /**
   * A stand-in host entity for the conversation.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_conversation_message');

    $this->recorder = $this->container->get(MessageRecorderInterface::class);
    $this->host = User::create(['name' => 'session-host']);
    $this->host->save();
  }

  /**
   * Tests recording a user turn with its author.
   */
  public function testRecordUser(): void {
    $user = $this->recorder->recordUser($this->host, 'Draft a news article.', 1);

    $this->assertSame(AiConversationMessageInterface::ROLE_USER, $user->getRole());
    $this->assertSame('Draft a news article.', $user->get('content')->value);
    $this->assertSame(1, (int) $user->get('uid')->target_id);
    $this->assertSame($this->host->getEntityTypeId(), $user->getHostEntityType());
    $this->assertSame((int) $this->host->id(), $user->getHostEntityId());
    $this->assertNull($user->getParentId());
  }

  /**
   * Tests recording an assistant turn with normalized token usage.
   */
  public function testRecordAssistant(): void {
    $output = new ChatOutput(
      new ChatMessage('assistant', 'Here is the plan.'),
      [],
      [],
      new TokenUsageDto(10, 5, 15)
    );
    $assistant = $this->recorder->recordAssistant(
      $this->host,
      $output,
      'orchestrator',
      'mistral',
      'mistral-large-latest'
    );

    $this->assertSame(AiConversationMessageInterface::ROLE_ASSISTANT, $assistant->getRole());
    $this->assertSame('Here is the plan.', $assistant->get('content')->value);
    $this->assertSame('orchestrator', $assistant->get('agent_id')->value);
    $this->assertSame('mistral', $assistant->get('provider')->value);
    $this->assertSame('mistral-large-latest', $assistant->get('model')->value);
    $this->assertNull($assistant->getParentId());

    // The token usage is normalized from the DTO into the discrete columns.
    $this->assertSame(
      ['input' => 10, 'output' => 5, 'total' => 15, 'reasoning' => NULL, 'cached' => NULL],
      $assistant->getTokenUsage()
    );

    // An assistant turn with no author keeps a NULL owner.
    $this->assertNull($assistant->get('uid')->target_id);
  }

  /**
   * Tests that a streamed output records the reconstructed text and tokens.
   *
   * For a streamed response the token usage and final text live on the
   * reconstructed output, not the original one handed to the recorder.
   */
  public function testRecordAssistantFromStreamedOutput(): void {
    // The reconstructed output carries the final text and token usage.
    $reconstructed = new ChatOutput(
      new ChatMessage('assistant', 'Streamed answer.'),
      [],
      [],
      new TokenUsageDto(20, 8, 28)
    );
    // The streamed iterator reconstructs into that output. The original
    // output the callback receives has an empty token usage.
    $iterator = $this->createMock(StreamedChatMessageIteratorInterface::class);
    $iterator->method('reconstructChatOutput')->willReturn($reconstructed);
    $streamed = new ChatOutput($iterator, [], []);

    $assistant = $this->recorder->recordAssistant(
      $this->host,
      $streamed,
      'orchestrator',
      'mistral',
      'mistral-large-latest'
    );

    $this->assertSame('Streamed answer.', $assistant->get('content')->value);
    $this->assertSame(
      ['input' => 20, 'output' => 8, 'total' => 28, 'reasoning' => NULL, 'cached' => NULL],
      $assistant->getTokenUsage()
    );
  }

  /**
   * Tests recording a plain assistant text turn.
   */
  public function testRecordAssistantText(): void {
    $message = $this->recorder->recordAssistantText(
      $this->host,
      'Draft generated with 3 fields. Review the content on the right.',
      'orchestrator'
    );

    $this->assertSame(AiConversationMessageInterface::ROLE_ASSISTANT, $message->getRole());
    $this->assertSame(
      'Draft generated with 3 fields. Review the content on the right.',
      $message->get('content')->value
    );
    $this->assertSame('orchestrator', $message->get('agent_id')->value);
    $this->assertNull($message->getParentId());
  }

  /**
   * Tests recording a sub-agent system prompt row under a parent.
   */
  public function testRecordSystem(): void {
    $parent = $this->recorder->recordUser($this->host, 'Draft a news article.', 1);
    $system = $this->recorder->recordSystem($this->host, 'You are a content generator.', 'title-agent', $parent);

    $this->assertSame(AiConversationMessageInterface::ROLE_SYSTEM, $system->getRole());
    $this->assertSame('You are a content generator.', $system->get('content')->value);
    $this->assertSame('title-agent', $system->get('agent_id')->value);
    $this->assertSame((int) $parent->id(), $system->getParentId());
  }

  /**
   * Tests recording tool and error turns nested under a parent.
   */
  public function testRecordToolAndErrorUnderParent(): void {
    $parent = $this->recorder->recordUser($this->host, 'Draft a news article.', 1);

    $tool = $this->recorder->recordTool($this->host, 'Tool result payload.', $parent);
    $this->assertSame(AiConversationMessageInterface::ROLE_TOOL, $tool->getRole());
    $this->assertSame('Tool result payload.', $tool->get('content')->value);
    $this->assertSame((int) $parent->id(), $tool->getParentId());

    $error = $this->recorder->recordError($this->host, 'Boom.', 'orchestrator', $parent);
    $this->assertSame(AiConversationMessageInterface::ROLE_ERROR, $error->getRole());
    $this->assertSame('Boom.', $error->get('content')->value);
    $this->assertSame('orchestrator', $error->get('agent_id')->value);
    $this->assertSame((int) $parent->id(), $error->getParentId());
  }

}
