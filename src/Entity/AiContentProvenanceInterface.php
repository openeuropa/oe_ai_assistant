<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for AI content provenance records.
 */
interface AiContentProvenanceInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Returns the tracked entity type id.
   */
  public function getTrackedEntityTypeId(): string;

  /**
   * Returns the tracked entity id.
   */
  public function getTrackedEntityId(): int;

  /**
   * Returns the tracked revision id.
   */
  public function getTrackedRevisionId(): int;

  /**
   * Returns the editorial session, or NULL.
   */
  public function getSession(): ?AiEditorialSessionInterface;

  /**
   * Returns the triggering assistant message, or NULL.
   */
  public function getMessage(): ?AiConversationMessageInterface;

  /**
   * Returns the drafting template id, or NULL.
   */
  public function getTemplateId(): ?string;

  /**
   * Returns the token usage snapshot.
   *
   * @return array<string, int>
   *   Keys input, output and total.
   */
  public function getTokenUsage(): array;

  /**
   * Returns the provider id snapshot.
   */
  public function getProvider(): string;

  /**
   * Returns the model id snapshot.
   */
  public function getModel(): string;

  /**
   * Returns the entity version snapshot.
   *
   * @return array<string, int|null>
   *   Keys major, minor and patch; NULL values when the revision has no
   *   entity_version field.
   */
  public function getVersion(): array;

  /**
   * Returns the creation timestamp.
   */
  public function getCreatedTime(): int;

}
