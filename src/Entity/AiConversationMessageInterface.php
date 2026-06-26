<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the interface for AI conversation messages.
 *
 * A single message in an LLM conversation (a user / assistant / system / tool /
 * error turn). Messages are grouped into a conversation by their owner
 * (owner_entity_type + owner_entity_id) and nested via the parent reference,
 * which builds the sub-agent tree.
 */
interface AiConversationMessageInterface extends ContentEntityInterface {

  /**
   * Role: a system / instruction message (e.g. a sub-agent system prompt).
   */
  public const ROLE_SYSTEM = 'system';

  /**
   * Role: a message authored by a user.
   */
  public const ROLE_USER = 'user';

  /**
   * Role: a message produced by the assistant / an agent.
   */
  public const ROLE_ASSISTANT = 'assistant';

  /**
   * Role: a tool result.
   */
  public const ROLE_TOOL = 'tool';

  /**
   * Role: an error (LLM/provider, tool, or application failure).
   */
  public const ROLE_ERROR = 'error';

  /**
   * Returns the message role.
   */
  public function getRole(): string;

  /**
   * Returns the owning entity type id.
   */
  public function getOwnerEntityType(): string;

  /**
   * Returns the owning entity id.
   */
  public function getOwnerEntityId(): ?int;

  /**
   * Returns the parent message id, or NULL for a top-level turn.
   */
  public function getParentId(): ?int;

  /**
   * Returns the decoded tool calls.
   *
   * @return array
   *   The tool calls, or an empty array when none are stored.
   */
  public function getToolCalls(): array;

  /**
   * Sets the tool calls.
   *
   * @param array $tool_calls
   *   The tool calls; serialized to JSON for storage.
   */
  public function setToolCalls(array $tool_calls): static;

  /**
   * Returns the decoded token usage.
   *
   * @return array
   *   The token usage, or an empty array when none is stored.
   */
  public function getTokenUsage(): array;

  /**
   * Sets the token usage.
   *
   * @param array $token_usage
   *   The token usage; serialized to JSON for storage.
   */
  public function setTokenUsage(array $token_usage): static;

  /**
   * Returns the decoded metadata.
   *
   * @return array
   *   The metadata, or an empty array when none is stored.
   */
  public function getMetadata(): array;

  /**
   * Sets the metadata.
   *
   * @param array $metadata
   *   The metadata; serialized to JSON for storage.
   */
  public function setMetadata(array $metadata): static;

}
