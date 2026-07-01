<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Records conversation turns as ai_conversation_message rows.
 *
 * The write side of the conversation store: it creates a persisted message for
 * a user string, an assistant ChatOutput, a tool result, or an error, hosted by
 * the given entity and optionally nested under a parent turn.
 */
interface MessageRecorderInterface {

  /**
   * Records a user turn.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $text
   *   The user message text.
   * @param int|null $uid
   *   The author user id, or NULL when unknown.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  public function recordUser(EntityInterface $host, string $text, ?int $uid = NULL): AiConversationMessageInterface;

  /**
   * Records an assistant turn from a provider ChatOutput.
   *
   * Normalizes the token usage into columns and captures the rendered tool
   * calls. A streamed output is reconstructed into its final message first.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $output
   *   The provider output for this turn.
   * @param string $agentId
   *   Which agent produced the turn (orchestrator or a sub-agent id).
   * @param string $provider
   *   The AI provider id.
   * @param string $model
   *   The model id.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The parent turn for a sub-agent call, or NULL for a top-level turn.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  public function recordAssistant(EntityInterface $host, ChatOutput $output, string $agentId, string $provider, string $model, ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface;

  /**
   * Records a tool result turn.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $content
   *   The tool result text.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The parent turn, or NULL for a top-level turn.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  public function recordTool(EntityInterface $host, string $content, ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface;

  /**
   * Records an error turn.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation.
   * @param string $message
   *   The error message.
   * @param string $agentId
   *   Which agent the error occurred under, if known.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The parent turn, or NULL for a top-level turn.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  public function recordError(EntityInterface $host, string $message, string $agentId = '', ?AiConversationMessageInterface $parent = NULL): AiConversationMessageInterface;

}
