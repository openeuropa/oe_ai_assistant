<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Reads and removes the stored conversations of one editorial session.
 */
interface SessionConversationInterface {

  /**
   * The rows of one session, oldest first.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   * @param string|null $agentId
   *   The agent to read, or NULL for every agent of the session.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The rows.
   */
  public function rows(AiEditorialSessionInterface $session, ?string $agentId = NULL): array;

  /**
   * Every conversation of one session, grouped by thread.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return array
   *   The rows, keyed by thread ID, each thread oldest row first.
   */
  public function threads(AiEditorialSessionInterface $session): array;

  /**
   * What the tools of one session answered, in the order they ran.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return array
   *   One entry per settled call, shaped {name, result}, the result decoded.
   */
  public function toolResults(AiEditorialSessionInterface $session): array;

  /**
   * Removes every conversation of one session, drafter runs included.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   */
  public function deleteFor(AiEditorialSessionInterface $session): void;

}
