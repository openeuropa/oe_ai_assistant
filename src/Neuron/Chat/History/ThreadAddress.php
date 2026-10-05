<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

/**
 * What a Neuron thread id says about the conversation it addresses.
 *
 * Ai_neuron composes a thread id as "<plugin id>.<key>", where the key is
 * whatever the plugin returns from threadKey() or a caller passes to
 * createAgent(). The drafting agents use the editorial session id, and a
 * drafter appends the turn its rows nest under, so a drafter thread reads
 * "field_group.12.345".
 */
final class ThreadAddress {

  /**
   * What marks a thread as one of this module's editorial sessions.
   *
   * The store is handed to every agent the site builds, including agents of
   * other modules, so a thread it does not recognise must be left alone
   * rather than read as a session id.
   */
  private const PREFIX = 'session-';

  /**
   * ThreadAddress constructor.
   *
   * @param string $agentId
   *   The plugin id of the agent holding the conversation.
   * @param string $sessionId
   *   The editorial session the conversation belongs to.
   * @param string|null $parentId
   *   The conversation message the rows nest under, or NULL when the turn
   *   was not known when the conversation opened.
   * @param bool $nested
   *   Whether this is a conversation nested under a turn rather than the
   *   editor's own, which is what decides whether it replays.
   * @param string $label
   *   What the rows of this conversation are tagged with: the field group a
   *   drafter writes, or the plugin id when it names nothing finer.
   */
  private function __construct(
    public readonly string $agentId,
    public readonly string $sessionId,
    public readonly ?string $parentId = NULL,
    public readonly bool $nested = FALSE,
    public readonly string $label = '',
  ) {}

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
   * Builds the key of a conversation nested under a turn.
   *
   * The parent segment is there even when the turn is unknown, since it is
   * what tells a nested conversation from the editor's own, and only the
   * editor's is ever replayed to a model.
   *
   * @param string $sessionId
   *   The editorial session.
   * @param string|null $parentId
   *   The turn the rows nest under, or NULL to leave them at the top level.
   * @param string|null $label
   *   What to tag the rows with, or NULL to tag them with the plugin id.
   *
   * @return string
   *   The key, which ai_neuron puts the plugin id in front of.
   */
  public static function nested(string $sessionId, ?string $parentId, ?string $label = NULL): string {
    return self::PREFIX . $sessionId . '.' . ($parentId ?? '0') . ($label === NULL ? '' : '.' . $label);
  }

  /**
   * Reads a thread id back into its parts.
   *
   * @param string $threadId
   *   The thread id Neuron holds.
   *
   * @return self|null
   *   The address, or NULL for a thread this module did not compose.
   */
  public static function parse(string $threadId): ?self {
    $parts = explode('.', $threadId);
    if (count($parts) < 2 || !str_starts_with($parts[1], self::PREFIX)) {
      return NULL;
    }

    $parent = $parts[2] ?? NULL;

    return new self(
      $parts[0],
      substr($parts[1], strlen(self::PREFIX)),
      $parent === '0' ? NULL : $parent,
      count($parts) > 2,
      $parts[3] ?? $parts[0],
    );
  }

}
