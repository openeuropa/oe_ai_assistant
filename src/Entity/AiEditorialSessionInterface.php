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
   * Returns the thread that holds the editor's own conversation.
   */
  public function editorialThread(): string;

  /**
   * Returns what the editor and the assistant said, oldest first.
   *
   * A draft is written to a thread of its own, and a tool call carries no
   * text. So these turns are the conversation with no draft in it.
   *
   * @return array
   *   One entry per turn, shaped {role, text}.
   */
  public function conversationTurns(): array;

  /**
   * Returns the stored rows of one thread of this session.
   *
   * A caller that only reads one thread does not have to load and group
   * every thread of the session to get it.
   *
   * @param string $threadId
   *   The thread ID, as ai_neuron composed it.
   *
   * @return \Drupal\ai_neuron\Entity\NeuronMessageInterface[]
   *   The thread's rows, oldest first; empty when the thread has none.
   */
  public function conversationThread(string $threadId): array;

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

  /**
   * Records one entry in the activity of this session.
   *
   * The entry stores the current user and the time of the write. This
   * method never throws. A write that fails only goes to the log, so
   * recording cannot break the change the caller just made.
   *
   * @param \Drupal\oe_ai_assistant\Entity\ActivityType $type
   *   The kind of entry to record.
   * @param string $message
   *   The sentence to record, or a sprintf() format when the call passes
   *   arguments.
   * @param string|int|float ...$arguments
   *   The values for the format.
   */
  public function recordActivity(ActivityType $type, string $message, string|int|float ...$arguments): void;

  /**
   * Returns the most recent entries in the activity of this session.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface[]
   *   The entries, oldest first.
   */
  public function getLatestActivity(int $limit): array;

  /**
   * Counts the entries in the activity of this session.
   */
  public function countActivity(): int;

  /**
   * Removes every entry in the activity of this session.
   */
  public function deleteActivity(): void;

}
