<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

/**
 * Names the threads of one editorial session.
 *
 * Ai_neuron composes a thread id as "<plugin id>.<key>", where the key is what
 * a plugin returns from threadKey() or runKey(). The editor's conversation is
 * keyed by the session, and a drafter run by the session, the chat turn and
 * the field group, so a drafter thread reads
 * "field_group.session-12.a1b2c3.main_fields".
 */
final class ThreadAddress {

  /**
   * What marks a thread as one of this module's editorial sessions.
   */
  private const PREFIX = 'session-';

  /**
   * Builds the key of the editor's own conversation.
   *
   * @param string $sessionId
   *   The editorial session.
   *
   * @return string
   *   The key, which ai_neuron puts the plugin id in front of.
   */
  public static function key(string $sessionId): string {
    return self::PREFIX . $sessionId;
  }

  /**
   * The thread the editor's conversation with one agent is held under.
   *
   * @param string $agentId
   *   The plugin holding the conversation.
   * @param string $sessionId
   *   The editorial session.
   *
   * @return string
   *   The thread id, as ai_neuron composes it.
   */
  public static function thread(string $agentId, string $sessionId): string {
    return $agentId . '.' . self::key($sessionId);
  }

  /**
   * Builds the key of a run started by one chat turn.
   *
   * The turn segment is what keeps each run to a thread of its own. Two runs
   * sharing a thread would replay the first one's conversation into the
   * second, and a drafter is asked for one answer rather than a conversation.
   *
   * @param string $sessionId
   *   The editorial session.
   * @param string $turn
   *   What tells this chat turn from another of the same session.
   * @param string $label
   *   What this run answers for, such as the field group being drafted.
   *
   * @return string
   *   The key, which ai_neuron puts the plugin id in front of.
   */
  public static function nested(string $sessionId, string $turn, string $label): string {
    return self::key($sessionId) . '.' . $turn . '.' . $label;
  }

}
