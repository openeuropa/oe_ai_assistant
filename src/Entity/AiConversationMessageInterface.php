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

}
