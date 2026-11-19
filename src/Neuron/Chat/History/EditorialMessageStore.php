<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\History;

use Drupal\ai_neuron\Chat\History\DrupalMessageStore;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use Drupal\oe_ai_assistant\Plugin\NeuronTool\SaveDraftNeuronTool;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ApprovalState;

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
    if ($message instanceof ToolResultMessage) {
      $this->storeCancelledSaves($entity, $message);
    }

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
   * Stores a cancelled save with the cancel text alone.
   *
   * Neuron answers a rejected call with a wrapper that tells the model never
   * to call the tool again, and the model then refuses the editor's next
   * request to save. Later turns read the result from this row, so the row
   * keeps only the cancel text.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The unsaved row.
   * @param \NeuronAI\Chat\Messages\ToolResultMessage $message
   *   The results the row stores.
   */
  private function storeCancelledSaves(ContentEntityInterface $entity, ToolResultMessage $message): void {
    $cancelled = FALSE;
    foreach ($message->getToolCalls() as $call) {
      if ($call->getName() === SaveDraftNeuronTool::TOOL_ID && $call->getApprovalState() === ApprovalState::Rejected) {
        $call->setResult(SaveDraftNeuronTool::CANCELLED . '.');
        $cancelled = TRUE;
      }
    }
    if (!$cancelled) {
      return;
    }

    // The row was built before this ran, so its meta column is written again
    // the way the parent store writes it: everything but the role and content.
    $meta = $message->jsonSerialize();
    unset($meta['role'], $meta['content']);
    $entity->set('meta', json_encode($meta, JSON_THROW_ON_ERROR));
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
