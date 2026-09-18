<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\RevisionableInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Records AI provenance for a saved revision.
 */
interface ProvenanceRecorderInterface {

  /**
   * Records provenance for a saved revision.
   *
   * @param \Drupal\Core\Entity\RevisionableInterface $entity
   *   The saved revision.
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The editorial session that produced the revision.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The assistant turn that triggered drafting.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface|null
   *   The saved record, the existing record when the revision was already
   *   tracked, or NULL when the write failed (logged, not thrown).
   */
  public function record(RevisionableInterface $entity, AiEditorialSessionInterface $session, AiConversationMessageInterface $message): ?AiContentProvenanceInterface;

}
