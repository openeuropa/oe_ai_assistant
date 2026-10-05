<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Neuron\Tools\ToolResult;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Neuron message store backed by the conversation message entities.
 *
 * Neuron addresses a conversation by a thread id, which ai_neuron composes
 * as "<plugin id>.<key>". The drafting agents use the editorial session as
 * the key and a drafter adds the turn its rows nest under, so this store
 * reads both back out of the thread id and writes rows against them.
 *
 * Loading replays the persisted transcript as the alternating user and
 * assistant turns Neuron requires, so consecutive rows of one role become
 * one message with several text blocks. An editorial change is not a turn
 * and stays out of it; get_session_history reads those rows instead.
 */
final class ConversationMessageStore implements MessageStoreInterface {

  /**
   * Maximum top-level rows replayed to the model.
   */
  private const MAX_ROWS = 40;

  /**
   * The last assistant row persisted per thread.
   *
   * @var array<string, \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface>
   */
  private array $lastAssistant = [];

  /**
   * The ids of the assistant messages already written, per thread.
   *
   * @var array<string, array<string, true>>
   */
  private array $written = [];

  public function __construct(
    private readonly MessageRecorderInterface $recorder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'current_user')]
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadActive(string $threadId): array {
    $address = ThreadAddress::parse($threadId);
    // A drafter writes under the turn that called it and never replays:
    // its task carries the context it needs, and the editor's conversation
    // is not the drafter's.
    if ($address === NULL || $address->nested) {
      return [];
    }
    $session = $this->session($address->sessionId);
    if ($session === NULL) {
      return [];
    }

    $rows = array_values(array_filter(
      $this->messageStorage()->loadTranscript($session),
      static fn (AiConversationMessageInterface $row): bool => $row->getRole() !== AiConversationMessageInterface::ROLE_TOOL,
    ));

    return $this->replay(array_slice($rows, -self::MAX_ROWS));
  }

  /**
   * {@inheritdoc}
   *
   * The whole thread is the whole transcript, which the row cap of
   * loadActive() is the only thing that shortens, so this reads the same
   * rows without that cap.
   */
  public function loadAll(string $threadId, ?int $limit = NULL, ?string $before = NULL): array {
    $address = ThreadAddress::parse($threadId);
    $session = $address === NULL ? NULL : $this->session($address->sessionId);
    if ($session === NULL) {
      return [];
    }

    $rows = array_values(array_filter(
      $this->messageStorage()->loadTranscript($session),
      static fn (AiConversationMessageInterface $row): bool => $row->getRole() !== AiConversationMessageInterface::ROLE_TOOL,
    ));

    return $this->replay($limit === NULL ? $rows : array_slice($rows, -$limit));
  }

  /**
   * {@inheritdoc}
   */
  public function append(string $threadId, Message $message): void {
    $address = ThreadAddress::parse($threadId);
    $session = $address === NULL ? NULL : $this->session($address->sessionId);
    if ($session === NULL) {
      return;
    }
    $parent = $address->parentId === NULL ? NULL : $this->message($address->parentId);

    if ($message instanceof ToolResultMessage) {
      foreach ($message->getToolCalls() as $call) {
        $this->recorder->recordTool($session, (string) $call->getResult(), $parent);
      }
      return;
    }

    if ($message instanceof AssistantMessage) {
      $this->writeAssistant($threadId, $message);
      return;
    }

    if ($message instanceof UserMessage) {
      $this->recorder->recordUser(
        $session,
        $message->getContent() ?? '',
        (int) $this->currentUser->id(),
        $parent,
        $parent === NULL ? '' : $address->label,
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * The transcript is the editorial record of the session, so a message
   * that falls out of the model's context window stays as it is. The row
   * cap of loadActive() is what keeps the replay short.
   */
  public function archive(string $threadId, int $count): void {}

  /**
   * {@inheritdoc}
   *
   * The plugin's reset action deletes the conversation itself, since it
   * also clears what the app shows, so there is nothing to do here.
   */
  public function clear(string $threadId): void {
    unset($this->lastAssistant[$threadId], $this->written[$threadId]);
  }

  /**
   * Writes an assistant turn the model has just produced.
   *
   * A turn that asks for tools reaches the history only after the tools
   * have run, which is too late for a tool that nests its own rows under
   * the turn that called it. Writing it when the model answers gives the
   * tools a turn to nest under, and append() then recognises it.
   *
   * @param string $threadId
   *   The thread the turn belongs to.
   * @param \NeuronAI\Chat\Messages\AssistantMessage $message
   *   The turn the model produced.
   */
  public function recordAssistant(string $threadId, AssistantMessage $message): void {
    $this->writeAssistant($threadId, $message);
  }

  /**
   * Returns the last assistant row written on a thread, if any.
   *
   * @param string $threadId
   *   The thread to look at.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null
   *   The row, or NULL when the thread has no assistant turn yet.
   */
  public function lastAssistant(string $threadId): ?AiConversationMessageInterface {
    return $this->lastAssistant[$threadId] ?? NULL;
  }

  /**
   * Stores a finished tool's result on the call that requested it.
   *
   * Neuron adds tool results to the history only after the next model call,
   * so this runs when the tool finishes and the transcript can serve the
   * result while the model is still answering.
   *
   * @param string $threadId
   *   The thread whose last assistant turn made the call.
   * @param \NeuronAI\Tools\ToolCall $call
   *   The finished call.
   */
  public function attachToolResult(string $threadId, ToolCall $call): void {
    $row = $this->lastAssistant[$threadId] ?? NULL;
    if ($row === NULL) {
      return;
    }
    $result = ToolResult::decode((string) $call->getResult());
    $calls = $row->getToolCalls();
    foreach ($calls as &$entry) {
      if (($entry['id'] ?? '') === (string) $call->getCallId()) {
        $entry['result'] = $result;
      }
    }
    unset($entry);
    $row->setToolCalls($calls);
    $row->save();
  }

  /**
   * Writes an assistant turn once, whichever caller asks for it first.
   */
  private function writeAssistant(string $threadId, AssistantMessage $message): void {
    if (isset($this->written[$threadId][$message->getId()])) {
      return;
    }
    $address = ThreadAddress::parse($threadId);
    $session = $address === NULL ? NULL : $this->session($address->sessionId);
    if ($session === NULL) {
      return;
    }

    $usage = $message->getUsage();
    $this->written[$threadId][$message->getId()] = TRUE;
    $this->lastAssistant[$threadId] = $this->recorder->recordAssistantTurn(
      $session,
      $message->getContent() ?? '',
      $message instanceof ToolCallMessage ? array_map($this->renderToolCall(...), $message->getToolCalls()) : [],
      $usage === NULL ? [] : [
        'input' => $usage->inputTokens,
        'output' => $usage->outputTokens,
        'total' => $usage->getTotal(),
        'reasoning' => $usage->reasoningTokens,
        'cached' => $usage->cachedInputTokens,
      ],
      NULL,
      $address->label,
      $address->agentId,
      '',
      $address->parentId === NULL ? NULL : $this->message($address->parentId),
    );
  }

  /**
   * Replays conversation rows as alternating messages.
   *
   * Only the turns are replayed: an editorial change is recorded as its own
   * row and read by a tool, and a tool row is skipped because a stored
   * result cannot be re-linked to the call that produced it. Assistant rows
   * without text are skipped too, and a leading assistant run is dropped,
   * since the model expects a user turn first.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface[] $rows
   *   The rows to replay, oldest first.
   *
   * @return \NeuronAI\Chat\Messages\Message[]
   *   The replayed messages.
   */
  private function replay(array $rows): array {
    $messages = [];
    foreach ($rows as $row) {
      $text = (string) $row->get('content')->value;
      $role = $row->getRole();
      if ($role === AiConversationMessageInterface::ROLE_ASSISTANT && $text === '') {
        continue;
      }
      if (!in_array($role, [AiConversationMessageInterface::ROLE_USER, AiConversationMessageInterface::ROLE_ASSISTANT], TRUE)) {
        continue;
      }
      if ($messages === [] && $role === AiConversationMessageInterface::ROLE_ASSISTANT) {
        continue;
      }
      $last = end($messages);
      if ($last instanceof Message && $last->getRole() === $role) {
        $last->addContent(new TextContent($text));
        continue;
      }
      $messages[] = $role === AiConversationMessageInterface::ROLE_USER
        ? new UserMessage($text)
        : new AssistantMessage($text);
    }

    return $messages;
  }

  /**
   * Renders a tool call in the shape the transcript and draft history read.
   */
  private function renderToolCall(ToolCall $call): array {
    return [
      'id' => $call->getCallId(),
      'type' => 'function',
      'function' => [
        'name' => $call->getName(),
        'arguments' => json_encode($call->getInputs() === [] ? new \stdClass() : $call->getInputs()),
      ],
    ];
  }

  /**
   * Loads the editorial session of a thread, or NULL when it is gone.
   */
  private function session(string $id): ?AiEditorialSessionInterface {
    $session = $this->entityTypeManager->getStorage('ai_editorial_session')->load($id);
    return $session instanceof AiEditorialSessionInterface ? $session : NULL;
  }

  /**
   * Loads a conversation message row, or NULL when it is gone.
   */
  private function message(string $id): ?AiConversationMessageInterface {
    $row = $this->messageStorage()->load($id);
    return $row instanceof AiConversationMessageInterface ? $row : NULL;
  }

  /**
   * Returns the conversation message storage.
   */
  private function messageStorage(): AiConversationMessageStorageInterface {
    $storage = $this->entityTypeManager->getStorage('ai_conversation_message');
    assert($storage instanceof AiConversationMessageStorageInterface);
    return $storage;
  }

}
