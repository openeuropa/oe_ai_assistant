<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Default message recorder.
 */
class MessageRecorder implements MessageRecorderInterface {

  /**
   * Constructs a MessageRecorder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function recordUser(EntityInterface $host, string $text, ?int $uid = NULL): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_USER) + [
      'content' => $text,
    ];
    // The author is the Drupal owner, set for user turns only.
    if ($uid !== NULL) {
      $values['uid'] = $uid;
    }
    return $this->create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function recordAssistant(EntityInterface $host, ChatOutput $output, string $agentId, string $provider, string $model, ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    $message = $this->normalizedMessage($output);
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $row */
    $row = $this->storage()->create($this->base($host, AiConversationMessageInterface::ROLE_ASSISTANT, $parent) + [
      'agent_id' => $agentId,
      'provider' => $provider,
      'model' => $model,
      'content' => $message->getText(),
      'finish_reason' => $this->finishReason($output),
    ]);
    $row->setTokenUsage($output->getTokenUsage()->toArray());
    // Capture the rendered tool calls when the turn requested any.
    $tools = $message->getRenderedTools();
    if ($tools) {
      $row->setToolCalls($tools);
    }
    $row->save();
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function recordTool(EntityInterface $host, string $content, ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    return $this->create($this->base($host, AiConversationMessageInterface::ROLE_TOOL, $parent) + [
      'content' => $content,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function recordError(EntityInterface $host, string $message, string $agentId = '', ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_ERROR, $parent) + [
      'content' => $message,
    ];
    if ($agentId !== '') {
      $values['agent_id'] = $agentId;
    }
    return $this->create($values);
  }

  /**
   * Builds the shared field values for a new message.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $role
   *   The message role.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The parent turn, or NULL for a top-level turn.
   *
   * @return array
   *   The base field values.
   */
  protected function base(EntityInterface $host, string $role, ?AiConversationMessageInterface $parent = NULL): array {
    return [
      'host_entity_type' => $host->getEntityTypeId(),
      'host_entity_id' => (int) $host->id(),
      'role' => $role,
      'parent' => $parent?->id(),
    ];
  }

  /**
   * Creates and saves a message from the given field values.
   *
   * @param array $values
   *   The field values.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  protected function create(array $values): AiConversationMessageInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $row */
    $row = $this->storage()->create($values);
    $row->save();
    return $row;
  }

  /**
   * Returns the final assistant message, reconstructing a streamed output.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $output
   *   The provider output.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The normalized message.
   */
  protected function normalizedMessage(ChatOutput $output): ChatMessage {
    $normalized = $output->getNormalized();
    if ($normalized instanceof StreamedChatMessageIteratorInterface) {
      $normalized = $normalized->reconstructChatOutput()->getNormalized();
    }
    return $normalized;
  }

  /**
   * Extracts the provider finish reason defensively.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $output
   *   The provider output.
   *
   * @return string|null
   *   The finish reason, or NULL when not reported.
   */
  protected function finishReason(ChatOutput $output): ?string {
    $normalized = $output->getNormalized();
    if ($normalized instanceof StreamedChatMessageIteratorInterface) {
      return $normalized->getFinishReason();
    }
    $raw = $output->getRawOutput();
    return is_array($raw) ? ($raw['choices'][0]['finish_reason'] ?? NULL) : NULL;
  }

  /**
   * Returns the conversation message storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage handler.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('ai_conversation_message');
  }

}
