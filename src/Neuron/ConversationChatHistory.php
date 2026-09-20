<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;

/**
 * Neuron chat history backed by the conversation message entities.
 *
 * Loading replays the persisted transcript to the model; adding a message
 * persists it as a conversation row. Neuron requires strict user and
 * assistant alternation, so consecutive rows of one role become one message
 * with several text blocks, and editorial events travel as user notes.
 */
final class ConversationChatHistory extends AbstractChatHistory {

  /**
   * Maximum top-level rows replayed to the model.
   */
  private const MAX_ROWS = 40;

  /**
   * The last assistant row persisted, if any.
   */
  private ?AiConversationMessageInterface $lastAssistant = NULL;

  /**
   * ConversationChatHistory constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $recorder
   *   The message recorder.
   * @param \Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageInterface $storage
   *   The conversation message storage.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $agentId
   *   The agent id stored on assistant rows.
   * @param string $providerId
   *   The drupal/ai provider id stored on assistant rows.
   * @param string $modelId
   *   The model id stored on assistant rows.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn every row nests under, or NULL for the top-level transcript.
   * @param int|null $authorId
   *   The user id stored on user rows, or NULL when not attributable.
   * @param string[] $unrecordedTools
   *   Tool names whose results are not persisted, such as signal tools.
   * @param bool $load
   *   Whether to replay the persisted top-level transcript to the model.
   */
  public function __construct(
    private readonly MessageRecorderInterface $recorder,
    private readonly AiConversationMessageStorageInterface $storage,
    private readonly EntityInterface $host,
    private readonly string $agentId,
    private readonly string $providerId,
    private readonly string $modelId,
    private readonly ?AiConversationMessageInterface $parent = NULL,
    private readonly ?int $authorId = NULL,
    private readonly array $unrecordedTools = [],
    bool $load = TRUE,
  ) {
    // The row cap replaces token trimming; the window only has to be large.
    parent::__construct(contextWindow: 2000000);
    if ($load) {
      $this->history = $this->load();
    }
  }

  /**
   * {@inheritdoc}
   *
   * A user message following a user message merges into it, so notes and
   * the turn they precede reach the model as one message.
   */
  public function addMessage(Message $message): ChatHistoryInterface {
    $last = end($this->history);
    if ($this->isPlainUser($message) && $last instanceof Message && $this->isPlainUser($last)) {
      foreach ($message->getTextBlocks() as $block) {
        $last->addContent($block);
      }
    }
    else {
      $this->history[] = $message;
    }

    $this->trimHistory();
    $this->persist($message);

    return $this;
  }

  /**
   * Returns the last assistant row persisted, if any.
   */
  public function lastAssistant(): ?AiConversationMessageInterface {
    return $this->lastAssistant;
  }

  /**
   * Persists a message as a conversation row.
   */
  private function persist(Message $message): void {
    if ($message instanceof ToolResultMessage) {
      foreach ($message->getTools() as $tool) {
        if (!in_array($tool->getName(), $this->unrecordedTools, TRUE)) {
          $this->recorder->recordTool($this->host, $tool->getResult(), $this->parent);
        }
      }
      return;
    }
    if ($message instanceof AssistantMessage) {
      $usage = $message->getUsage();
      $this->lastAssistant = $this->recorder->recordAssistantTurn(
        $this->host,
        $message->getContent() ?? '',
        $message instanceof ToolCallMessage ? array_map($this->renderToolCall(...), $message->getTools()) : [],
        $usage === NULL ? [] : [
          'input' => $usage->inputTokens,
          'output' => $usage->outputTokens,
          'total' => $usage->getTotal(),
          'reasoning' => $usage->reasoningTokens,
          'cached' => $usage->cachedInputTokens,
        ],
        $message->getMetadata('stop_reason'),
        $this->agentId,
        $this->providerId,
        $this->modelId,
        $this->parent,
      );
      return;
    }
    if ($message instanceof UserMessage) {
      $this->recorder->recordUser($this->host, $message->getContent() ?? '', $this->authorId, $this->parent, $this->parent === NULL ? '' : $this->agentId);
    }
  }

  /**
   * Replays the persisted transcript as alternating messages.
   *
   * Tool rows are skipped: a stored result cannot be re-linked to the call
   * that produced it. Assistant rows without text are skipped as well. A
   * leading assistant run is dropped, since the model expects a user turn
   * first.
   *
   * @return \NeuronAI\Chat\Messages\Message[]
   *   The replayed messages.
   */
  private function load(): array {
    $rows = array_values(array_filter(
      $this->storage->loadTranscript($this->host),
      static fn (AiConversationMessageInterface $row): bool => $row->getRole() !== AiConversationMessageInterface::ROLE_TOOL,
    ));
    $rows = array_slice($rows, -self::MAX_ROWS);

    $messages = [];
    foreach ($rows as $row) {
      $text = (string) $row->get('content')->value;
      $role = $row->getRole();
      if ($role === AiConversationMessageInterface::ROLE_EVENT) {
        $role = AiConversationMessageInterface::ROLE_USER;
        $text = '[Editorial change] ' . $text;
      }
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
   * Tells whether a message is a user turn rather than a tool result.
   */
  private function isPlainUser(Message $message): bool {
    return $message instanceof UserMessage && !$message instanceof ToolResultMessage;
  }

  /**
   * Renders a tool call in the shape the transcript and draft history read.
   */
  private function renderToolCall(ToolInterface $tool): array {
    return [
      'id' => $tool->getCallId(),
      'type' => 'function',
      'function' => [
        'name' => $tool->getName(),
        'arguments' => json_encode($tool->getInputs() === [] ? new \stdClass() : $tool->getInputs()),
      ],
    ];
  }

}
