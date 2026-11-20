<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for one entry of a session's activity record.
 */
interface AiEditorialSessionActivityInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Returns the session the entry belongs to.
   */
  public function getSession(): ?AiEditorialSessionInterface;

  /**
   * Returns the time of the entry, as a Unix timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Returns the kind of entry this is.
   */
  public function getType(): ActivityType;

  /**
   * Returns the sentence that says what happened.
   */
  public function getMessage(): string;

}
