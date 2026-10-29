<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\node\NodeInterface;
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

  /**
   * Returns the node saved by this session, if any.
   */
  public function getNode(): ?NodeInterface;

  /**
   * Sets the node saved by this session.
   */
  public function setNode(int $nid): self;

  /**
   * Returns the stored conversations of this session, grouped by thread.
   *
   * @return array
   *   The stored messages, keyed by thread ID, each thread oldest row first.
   */
  public function conversationThreads(): array;

  /**
   * Returns what the tools of this session answered, in the order they ran.
   *
   * @return array
   *   One entry per settled call, shaped {name, result}, the result decoded.
   */
  public function conversationToolResults(): array;

  /**
   * Removes every stored conversation of this session, sub-agent runs included.
   */
  public function deleteConversation(): void;

}
