<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;
use NeuronAI\Chat\Messages\Message;

/**
 * Records which editorial session a conversation belongs to.
 *
 * The session is what relates the editor's conversation to the drafter runs
 * it started, since each of those is a thread of its own. Querying the field
 * returns every message a session produced, which is what the back office
 * reads.
 */
final class EditorialMessageStore extends DrupalMessageStore {

  /**
   * The message type carrying the session reference.
   */
  private const BUNDLE = 'editorial_session';

  /**
   * Class constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn $turn
   *   Holds the session for the length of the request.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Passed up to the store.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Passed up to the store.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Passed up to the store.
   */
  public function __construct(
    private readonly DraftingTurn $turn,
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $currentUser,
    TimeInterface $time,
  ) {
    parent::__construct($entityTypeManager, $currentUser, $time);
  }

  /**
   * {@inheritdoc}
   *
   * An agent built outside a chat turn, such as the document summary, has no
   * session to record, so its rows are written as the module's plain type.
   */
  protected function bundle(): string {
    return $this->turn->isOpen() ? self::BUNDLE : parent::bundle();
  }

  /**
   * {@inheritdoc}
   */
  protected function preSave(ContentEntityInterface $entity, Message $message): void {
    if ($entity->bundle() === self::BUNDLE) {
      $entity->set('oe_ai_session', $this->turn->session()->id());
    }
  }

}
