<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Core\Entity\ContentEntityInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Records which editorial session a conversation belongs to.
 *
 * The session is what relates the editor's conversation to the drafter runs
 * it started, since each of those is a thread of its own. Querying the field
 * returns every message a session produced, which is what the back office
 * reads.
 *
 * The session is read out of the thread id, which every call names, so one
 * instance serves every conversation of the process as the interface expects.
 */
final class EditorialMessageStore extends DrupalMessageStore {

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
    $this->session = ThreadAddress::sessionOf($threadId);
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

}
