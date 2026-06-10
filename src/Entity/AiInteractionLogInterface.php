<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines the interface for AI interaction logs.
 */
interface AiInteractionLogInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Returns the provider request ID, if present.
   */
  public function getProviderRequestId(): ?string;

  /**
   * Returns the idempotency key used to deduplicate captured events.
   */
  public function getIdempotencyKey(): string;

}
