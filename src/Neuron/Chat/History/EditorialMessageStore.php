<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Core\Entity\ContentEntityInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Records which editorial session a conversation belongs to.
 *
 * The session is read out of the thread ID on every call, since one instance
 * serves every conversation the process builds. A key is prefixed because
 * this store is handed to every agent on the site, not just this module's.
 */
final class EditorialMessageStore extends DrupalMessageStore {

  /**
   * Marks a thread segment as one of this module's editorial sessions.
   *
   * An agent of this module leads its thread ID with the session it serves,
   * e.g. "session_12.agent_drafting" or
   * "session_12.agent_field_group.run_a1b2c3d4.group_main_fields".
   */
  public const string SESSION_PREFIX = 'session_';

  /**
   * The message type carrying the session reference.
   */
  private const BUNDLE = 'editorial_session';

  /**
   * The session of the thread being written, for the length of one append.
   */
  private ?string $session = NULL;

  /**
   * {@inheritdoc}
   *
   * The parent hands its seams the message and the row, not the thread, so the
   * session is read here and held until the row is written.
   */
  public function append(string $threadId, Message $message): void {
    $this->session = self::sessionOf($threadId);
    try {
      parent::append($threadId, $message);
    }
    finally {
      $this->session = NULL;
    }
  }

  /**
   * {@inheritdoc}
   *
   * A thread this module did not compose, such as another module's agent, has
   * no session to record, so its rows are written as the plain type.
   */
  protected function bundle(): string {
    return $this->session === NULL ? parent::bundle() : self::BUNDLE;
  }

  /**
   * {@inheritdoc}
   */
  protected function preSave(ContentEntityInterface $entity, Message $message): void {
    if ($this->session !== NULL) {
      $entity->set('oe_ai_session', $this->session);
    }
  }

  /**
   * The session a thread belongs to, if it is one of this module's.
   *
   * @param string $threadId
   *   The thread ID Neuron holds.
   *
   * @return string|null
   *   The editorial session ID, or NULL for a thread composed elsewhere.
   */
  private static function sessionOf(string $threadId): ?string {
    $key = explode('.', $threadId)[0];
    if (!str_starts_with($key, self::SESSION_PREFIX)) {
      return NULL;
    }
    $session = substr($key, strlen(self::SESSION_PREFIX));

    return $session === '' ? NULL : $session;
  }

}
