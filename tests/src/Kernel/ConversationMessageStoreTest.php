<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ConversationMessageStore;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\UserInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;

/**
 * Kernel tests for the message store backed by conversation messages.
 *
 * @group oe_ai_assistant
 */
class ConversationMessageStoreTest extends AiEditorialSessionKernelTestBase {

  /**
   * The message recorder.
   */
  protected MessageRecorderInterface $recorder;

  /**
   * The store under test.
   */
  protected ConversationMessageStore $store;

  /**
   * The session holding the conversation.
   */
  protected AiEditorialSessionInterface $session;

  /**
   * The session owner, who authors the user rows.
   */
  protected UserInterface $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->recorder = $this->container->get(MessageRecorderInterface::class);
    $this->store = $this->container->get(ConversationMessageStore::class);
    $this->owner = $this->createUser();
    $this->session = $this->createSession($this->owner);
    $this->container->get('current_user')->setAccount($this->owner);
  }

  /**
   * Returns the thread the editorial conversation is held under.
   */
  private function thread(?string $parentId = NULL): string {
    $session = (string) $this->session->id();

    return $parentId === NULL
      ? 'drafting.' . ThreadAddress::key($session)
      : 'field_group.' . ThreadAddress::nested($session, $parentId);
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
   * Tests that the transcript replays as the turns alone.
   *
   * Tool rows, assistant rows without text and editorial changes are all
   * skipped: a change is not a turn, and get_session_history reads those
   * rows instead. Consecutive rows of one role become one message.
   */
  public function testLoadActiveReplaysTheTurnsOnly(): void {
    // Creating the session already records a "Session started" change.
    $this->recorder->recordUser($this->session, 'Draft a news article.', (int) $this->owner->id());
    $this->recorder->recordAssistantTurn($this->session, '', [
      ['type' => 'function', 'function' => ['name' => 'get_draft_history', 'arguments' => '{}']],
    ], [], 'tool_calls', 'drafting', 'mock_ai', 'mock-model');
    $this->recorder->recordTool($this->session, '{"drafts":[]}');
    $this->recorder->recordAssistantTurn($this->session, 'No drafts exist yet.', [], [], 'stop', 'drafting', 'mock_ai', 'mock-model');
    $this->recorder->recordEvent($this->session, 'Tone changed to Formal', ['type' => 'tone']);

    $this->assertSame([
      ['user', ['Draft a news article.']],
      ['assistant', ['No drafts exist yet.']],
    ], $this->shape($this->store->loadActive($this->thread())));
  }

  /**
   * Tests that a drafter thread replays nothing and keeps its rows nested.
   *
   * Its task carries the context it needs, and the editor's conversation is
   * not the drafter's.
   */
  public function testDrafterThreadDoesNotReplayTheConversation(): void {
    $this->recorder->recordUser($this->session, 'Draft a news article.', (int) $this->owner->id());
    $parent = $this->recorder->recordAssistantTurn($this->session, '', [], [], 'tool_calls', 'drafting', 'mock_ai', 'mock-model');

    $this->assertSame([], $this->store->loadActive($this->thread((string) $parent->id())));
  }

  /**
   * Tests that a user turn persists against the session with its author.
   */
  public function testAppendPersistsUserTurn(): void {
    $this->store->append($this->thread(), new UserMessage('Go ahead.'));

    $rows = $this->transcript();
    $last = end($rows);
    $this->assertSame('user', $last->getRole());
    $this->assertSame('Go ahead.', $last->get('content')->value);
    $this->assertSame((int) $this->owner->id(), (int) $last->get('uid')->target_id);
  }

  /**
   * Tests that assistant turns and tool results persist with their details.
   *
   * A drafter thread nests every row under its parent turn and tags it with
   * the agent id. Each tool result becomes a tool row, and attaching it
   * stores it on the call that requested it.
   */
  public function testAppendPersistsAssistantAndToolRows(): void {
    $parent = $this->recorder->recordAssistantTurn($this->session, '', [], [], 'tool_calls', 'drafting', 'mock_ai', 'mock-model');
    $thread = $this->thread((string) $parent->id());

    $lookup = ToolCall::make('get_draft_history', 'call_1')->setResult('{"drafts":[]}');
    $echo = ToolCall::make('echo', 'call_2')->setResult('ok');

    $this->store->append($thread, new UserMessage('Generate the fields.'));
    $call = new ToolCallMessage(NULL, [$lookup, $echo]);
    $call->setUsage(new Usage(10, 4));
    $this->store->append($thread, $call);
    $this->store->attachToolResult($thread, $lookup);
    $this->store->attachToolResult($thread, $echo);
    $this->store->append($thread, new ToolResultMessage([$lookup, $echo]));
    $answer = new AssistantMessage('{"title": [{"value": "x"}]}');
    $answer->setUsage(new Usage(20, 8, 2, 1));
    $this->store->append($thread, $answer);

    $children = array_values(array_filter(
      $this->allRows(),
      fn (AiConversationMessageInterface $row) => $row->getParentId() === (int) $parent->id(),
    ));
    $this->assertSame(['user', 'assistant', 'tool', 'tool', 'assistant'], array_map(fn ($r) => $r->getRole(), $children));
    $this->assertSame(['field_group', 'field_group', '', '', 'field_group'], array_map(fn ($r) => (string) $r->get('agent_id')->value, $children));

    $calls = $children[1]->getToolCalls();
    $this->assertSame('get_draft_history', $calls[0]['function']['name']);
    $this->assertSame(['drafts' => []], $calls[0]['result']);
    $this->assertSame('echo', $calls[1]['function']['name']);
    $this->assertSame(['text' => 'ok'], $calls[1]['result']);
    $this->assertSame(['input' => 10, 'output' => 4, 'total' => 14, 'reasoning' => 0, 'cached' => 0], $children[1]->getTokenUsage());
    $this->assertSame('{"drafts":[]}', $children[2]->get('content')->value);
    $this->assertSame('ok', $children[3]->get('content')->value);
    $this->assertSame(['input' => 20, 'output' => 8, 'total' => 28, 'reasoning' => 1, 'cached' => 2], $children[4]->getTokenUsage());
    $this->assertSame((int) $children[4]->id(), (int) $this->store->lastAssistant($thread)->id());
  }

  /**
   * Loads the top-level transcript rows.
   */
  private function transcript(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    return $storage->loadTranscript($this->session);
  }

  /**
   * Loads every row hosted by the session, in creation order.
   */
  private function allRows(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('host_entity_type', $this->session->getEntityTypeId())
      ->condition('host_entity_id', (int) $this->session->id())
      ->sort('id')
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

}
