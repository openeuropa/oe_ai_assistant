<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\Entity\User;

/**
 * Kernel tests for the message recorder write side.
 *
 * Covers recording user, assistant, tool, and error turns as
 * ai_conversation_message rows hosted by an entity, with token usage
 * stored in columns and the parent linkage for drafter turns.
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
    'state_machine',
    'document_loader',
    'document_loader_tika',
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
   * Tests recording an assistant turn with token usage in columns.
   */
  public function testRecordAssistantTurn(): void {
    $assistant = $this->recorder->recordAssistantTurn(
      $this->host,
      'Here is the plan.',
      [],
      ['input' => 10, 'output' => 5, 'total' => 15],
      'stop',
      'orchestrator',
      'mistral',
      'mistral-large-latest'
    );

    $this->assertSame(AiConversationMessageInterface::ROLE_ASSISTANT, $assistant->getRole());
    $this->assertSame('Here is the plan.', $assistant->get('content')->value);
    $this->assertSame('orchestrator', $assistant->get('agent_id')->value);
    $this->assertSame('mistral', $assistant->get('provider')->value);
    $this->assertSame('mistral-large-latest', $assistant->get('model')->value);
    $this->assertSame('stop', $assistant->get('finish_reason')->value);
    $this->assertNull($assistant->getParentId());
    $this->assertSame([], $assistant->getToolCalls());

    // Missing token counts stay NULL rather than becoming zero.
    $this->assertSame(
      ['input' => 10, 'output' => 5, 'total' => 15, 'reasoning' => NULL, 'cached' => NULL],
      $assistant->getTokenUsage()
    );

    // An assistant turn with no author keeps a NULL owner.
    $this->assertNull($assistant->get('uid')->target_id);
  }

  /**
   * Tests that a turn requesting tools keeps the calls on the row.
   */
  public function testRecordAssistantTurnWithToolCalls(): void {
    $calls = [
      [
        'id' => 'call_1',
        'type' => 'function',
        'function' => ['name' => 'draft_group', 'arguments' => '{}'],
      ],
    ];
    $assistant = $this->recorder->recordAssistantTurn(
      $this->host, '', $calls, [], 'tool_calls', 'orchestrator', 'mistral', 'mistral-large-latest'
    );

    $this->assertSame('', (string) $assistant->get('content')->value);
    $this->assertSame($calls, $assistant->getToolCalls());
    $this->assertSame('tool_calls', $assistant->get('finish_reason')->value);
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
