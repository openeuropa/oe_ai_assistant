<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use NeuronAI\Chat\Messages\Message;

/**
 * Records which editorial session a conversation belongs to.
 */
final class EditorialMessageStore extends DrupalMessageStore {

  /**
   * {@inheritdoc}
   */
  protected function bundle(): string {
    $session = $this->agentPlugin?->getContextValues()['session'] ?? NULL;

    return $session instanceof AiEditorialSessionInterface ? 'editorial_session' : parent::bundle();
  }

  /**
   * {@inheritdoc}
   */
  protected function preSave(ContentEntityInterface $entity, Message $message): void {
    // Only the contexts the plugin declares are in here, so an agent without a
    // session yields NULL rather than an exception.
    $session = $this->agentPlugin?->getContextValues()['session'] ?? NULL;
    if ($session instanceof AiEditorialSessionInterface) {
      $entity->set('oe_ai_session', $session);
    }
  }

}
