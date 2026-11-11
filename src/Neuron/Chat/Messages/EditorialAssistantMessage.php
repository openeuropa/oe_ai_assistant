<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Chat\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;

/**
 * An assistant message that records which draft of a session it holds.
 */
final class EditorialAssistantMessage extends AssistantMessage {

  /**
   * The metadata key the version is kept under.
   */
  private const string DRAFT_VERSION = 'draft_version';

  /**
   * Records the version of the draft this message holds.
   *
   * @param string $version
   *   The version, as a draft names it.
   *
   * @return $this
   */
  public function setDraftVersion(string $version): static {
    $this->addMetadata(self::DRAFT_VERSION, $version);

    return $this;
  }

  /**
   * The version of the draft this message holds.
   *
   * @return string|null
   *   The version, or NULL for a message that holds no draft.
   */
  public function getDraftVersion(): ?string {
    $version = $this->getMetadata(self::DRAFT_VERSION);

    return is_string($version) ? $version : NULL;
  }

}
