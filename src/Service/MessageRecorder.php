<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

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
  public function recordUser(EntityInterface $host, string $text, ?int $uid = NULL, ?AiConversationMessageInterface $parent = NULL, string $agentId = ''): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_USER, $parent) + [
      'content' => $text,
    ];
    if ($agentId !== '') {
      $values['agent_id'] = $agentId;
    }
    // The author is the Drupal owner, set for user turns only.
    if ($uid !== NULL) {
      $values['uid'] = $uid;
    }
    return $this->create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function recordAssistantTurn(EntityInterface $host, string $text, array $toolCalls, array $tokenUsage, ?string $finishReason, string $agentId, string $provider, string $model, ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $row */
    $row = $this->storage()->create($this->base($host, AiConversationMessageInterface::ROLE_ASSISTANT, $parent) + [
      'agent_id' => $agentId,
      'provider' => $provider,
      'model' => $model,
      'content' => $text,
      'finish_reason' => $finishReason,
    ]);
    $row->setTokenUsage($tokenUsage);
    if ($toolCalls !== []) {
      $row->setToolCalls($toolCalls);
    }
    $row->save();
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function recordAssistantText(EntityInterface $host, string $text, string $agentId = '', ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_ASSISTANT, $parent) + [
      'content' => $text,
    ];
    if ($agentId !== '') {
      $values['agent_id'] = $agentId;
    }
    return $this->create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function recordSystem(EntityInterface $host, string $text, string $agentId = '', ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_SYSTEM, $parent) + [
      'content' => $text,
    ];
    if ($agentId !== '') {
      $values['agent_id'] = $agentId;
    }
    return $this->create($values);
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
   * {@inheritdoc}
   */
  public function recordEvent(EntityInterface $host, string $summary, array $metadata, ?int $uid = NULL): AiConversationMessageInterface {
    $values = $this->base($host, AiConversationMessageInterface::ROLE_EVENT) + [
      'content' => $summary,
    ];
    if ($uid !== NULL) {
      $values['uid'] = $uid;
    }
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $row */
    $row = $this->storage()->create($values);
    $row->setMetadata($metadata);
    $row->save();
    return $row;
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
   * Returns the conversation message storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage handler.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('ai_conversation_message');
  }

}
