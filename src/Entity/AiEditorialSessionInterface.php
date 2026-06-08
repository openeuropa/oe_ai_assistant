<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines the interface for AI editorial sessions.
 */
interface AiEditorialSessionInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Returns the target content type machine name.
   */
  public function getContentType(): string;

  /**
   * Returns the session status.
   */
  public function getStatus(): string;

  /**
   * Sets the session status.
   */
  public function setStatus(string $status): self;

}
