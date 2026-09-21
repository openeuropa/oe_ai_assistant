<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationChatHistory;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\Entity\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

/**
 * Kernel tests for the chat history backed by conversation messages.
 *
 * @group oe_ai_assistant
 */
class ConversationChatHistoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
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
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    'oe_ai_assistant',
    'state_machine',
    'document_loader',
    'document_loader_tika',
  ];

  /**
   * The message recorder.
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
   * Builds a history over the host, replaying the transcript by default.
   */
  private function history(?AiConversationMessageInterface $parent = NULL, bool $load = TRUE): ConversationChatHistory {
    return new ConversationChatHistory(
      $this->recorder,
      $this->container->get('entity_type.manager')->getStorage('ai_conversation_message'),
      $this->host,
      $parent === NULL ? 'orchestrator' : 'main_fields',
      'mock_ai',
      'mock-model',
      $parent,
      7,
      $load,
    );
  }

  /**
   * Returns each message as its role and text blocks.
   */
  private function shape(array $messages): array {
    return array_map(fn (Message $m) => [
      $m->getRole(),
      array_map(fn (TextContent $b) => $b->content, array_values($m->getTextBlocks())),
    ], $messages);
  }

  /**
   * Tests that the transcript replays as alternating turns with notes merged.
   *
   * Tool rows, agent event rows and assistant rows without text are skipped,
   * editorial events become user notes, and consecutive rows of one role
   * become one message.
   */
  public function testLoadReplaysAlternatingMessages(): void {
    $this->recorder->recordEvent($this->host, 'Session started', ['type' => 'session']);
    $this->recorder->recordUser($this->host, 'Draft a news article.', 7);
    $this->recorder->recordEvent($this->host, 'drafting: model call started', [
      'type' => 'agent',
      'event' => 'inference-start',
    ]);
    $this->recorder->recordAssistantTurn($this->host, '', [
      ['type' => 'function', 'function' => ['name' => 'get_draft_history', 'arguments' => '{}']],
    ], [], 'tool_calls', 'orchestrator', 'mock_ai', 'mock-model');
    $this->recorder->recordTool($this->host, '{"drafts":[]}');
    $this->recorder->recordAssistantTurn($this->host, 'No drafts exist yet.', [], [], 'stop', 'orchestrator', 'mock_ai', 'mock-model');
    $this->recorder->recordEvent($this->host, 'Tone changed to Formal', ['type' => 'tone']);

    $this->assertSame([
      ['user', ['[Editorial change] Session started', 'Draft a news article.']],
      ['assistant', ['No drafts exist yet.']],
      ['user', ['[Editorial change] Tone changed to Formal']],
    ], $this->shape($this->history()->getMessages()));
  }

  /**
   * Tests that a user turn after a trailing note merges into it and persists.
   */
  public function testAddUserMergesIntoTrailingUserTurn(): void {
    $this->recorder->recordUser($this->host, 'Draft a news article.', 7);
    $this->recorder->recordAssistantTurn($this->host, 'Sure.', [], [], 'stop', 'orchestrator', 'mock_ai', 'mock-model');
    $this->recorder->recordEvent($this->host, 'Tone changed to Formal', ['type' => 'tone']);

    $history = $this->history();
    $history->addMessage(new UserMessage('Go ahead.'));

    $this->assertSame([
      ['user', ['Draft a news article.']],
      ['assistant', ['Sure.']],
      ['user', ['[Editorial change] Tone changed to Formal', 'Go ahead.']],
    ], $this->shape($history->getMessages()));

    $rows = $this->transcript();
    $last = end($rows);
    $this->assertSame('user', $last->getRole());
    $this->assertSame('Go ahead.', $last->get('content')->value);
    $this->assertSame(7, (int) $last->get('uid')->target_id);
  }

  /**
   * Tests that assistant turns and tool results persist with their details.
   *
   * A drafter history nests every row under its parent turn and tags it with
   * the agent id. Each tool result becomes a tool row, and attaching it
   * stores it on the call that requested it.
   */
  public function testAddPersistsAssistantAndToolRows(): void {
    $parent = $this->recorder->recordAssistantTurn($this->host, '', [], [], 'tool_calls', 'orchestrator', 'mock_ai', 'mock-model');
    $history = $this->history($parent, FALSE);

    $lookup = Tool::make('get_draft_history', 'Lists drafts.')->setCallId('call_1')->setResult('{"drafts":[]}');
    $echo = Tool::make('echo', 'Echoes.')->setCallId('call_2')->setResult('ok');

    $history->addMessage(new UserMessage('Generate the fields.'));
    $call = new ToolCallMessage(NULL, [$lookup, $echo]);
    $call->setUsage(new Usage(10, 4));
    $call->setStopReason('tool_calls');
    $history->addMessage($call);
    $history->attachToolResult($lookup);
    $history->attachToolResult($echo);
    $history->addMessage(new ToolResultMessage([$lookup, $echo]));
    $answer = new AssistantMessage('{"title": [{"value": "x"}]}');
    $answer->setUsage(new Usage(20, 8, 2, 1));
    $history->addMessage($answer);

    $children = array_values(array_filter(
      $this->allRows(),
      fn (AiConversationMessageInterface $row) => $row->getParentId() === (int) $parent->id(),
    ));
    $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], array_map(fn ($r) => $r->getRole(), $children));
    $this->assertSame(['main_fields', 'main_fields', '', '', 'main_fields'], array_map(fn ($r) => (string) $r->get('agent_id')->value, $children));

    $calls = $children[1]->getToolCalls();
    $this->assertSame('get_draft_history', $calls[0]['function']['name']);
    $this->assertSame(['drafts' => []], $calls[0]['result']);
    $this->assertSame('echo', $calls[1]['function']['name']);
    $this->assertSame(['text' => 'ok'], $calls[1]['result']);
    $this->assertSame('tool_calls', $children[1]->get('finish_reason')->value);
    $this->assertSame(['input' => 10, 'output' => 4, 'total' => 14, 'reasoning' => 0, 'cached' => 0], $children[1]->getTokenUsage());
    $this->assertSame('{"drafts":[]}', $children[2]->get('content')->value);
    $this->assertSame('ok', $children[3]->get('content')->value);
    $this->assertSame(['input' => 20, 'output' => 8, 'total' => 28, 'reasoning' => 1, 'cached' => 2], $children[4]->getTokenUsage());
    $this->assertSame((int) $children[4]->id(), (int) $history->lastAssistant()->id());
  }

  /**
   * Loads the top-level transcript rows.
   */
  private function transcript(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    return $storage->loadTranscript($this->host);
  }

  /**
   * Loads every row hosted by the host, in creation order.
   */
  private function allRows(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('host_entity_type', $this->host->getEntityTypeId())
      ->condition('host_entity_id', (int) $this->host->id())
      ->sort('id')
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

}
