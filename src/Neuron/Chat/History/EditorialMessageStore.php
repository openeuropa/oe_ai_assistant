<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use NeuronAI\Chat\Messages\Message;

/**
 * Records which editorial session a conversation belongs to.
 */
final class EditorialMessageStore extends DrupalMessageStore {

  /**
   * The session bound by a caller. NULL on an unbound store.
   */
  private ?AiEditorialSessionInterface $session = NULL;

  /**
   * Binds the store to a session.
   *
   * No agent runs on the drafts thread of a session. The store therefore has
   * no plugin that names the session. A bound copy records the session on
   * every row it writes.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the rows belong to.
   *
   * @return static
   *   A copy bound to the session.
   */
  public function forSession(AiEditorialSessionInterface $session): static {
    $copy = clone $this;
    $copy->session = $session;

    return $copy;
  }

  /**
   * {@inheritdoc}
   */
  protected function bundle(): string {
    return $this->session() === NULL ? parent::bundle() : 'editorial_session';
  }

  /**
   * {@inheritdoc}
   */
  protected function preSave(ContentEntityInterface $entity, Message $message): void {
    $session = $this->session();
    if ($session === NULL) {
      return;
    }

    $entity->set('oe_ai_session', $session);

    // A reader asks for one draft of a session. The records are large, and a
    // scan decodes them all. The row therefore records its own version.
    if ($message instanceof EditorialAssistantMessage && $message->getDraftVersion() !== NULL) {
      $entity->set('oe_draft_version', $message->getDraftVersion());
    }
  }

  /**
   * The session the rows belong to.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface|null
   *   The bound session, the agent run's session otherwise, or NULL when
   *   neither names one.
   */
  private function session(): ?AiEditorialSessionInterface {
    // Only the contexts the plugin declares are in here, so an agent without a
    // session yields NULL rather than an exception.
    $session = $this->session ?? $this->agentPlugin?->getContextValues()['session'] ?? NULL;

    return $session instanceof AiEditorialSessionInterface ? $session : NULL;
  }

}
