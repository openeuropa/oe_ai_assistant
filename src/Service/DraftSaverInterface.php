<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Interface for saving a server-resolved drafting-session revision.
 */
interface DraftSaverInterface {

  /**
   * Saves one stored draft version on its session-owned node.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the draft.
   * @param array $fields
   *   The fields resolved from the stored draft result.
   * @param string|null $templateId
   *   The snapshot template ID, if any.
   * @param int $version
   *   The saved draft version.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The assistant message containing that stored draft result.
   *
   * @return array<string, string>
   *   The node ID and preview URL.
   */
  public function save(AiEditorialSessionInterface $session, array $fields, ?string $templateId, int $version, AiConversationMessageInterface $message): array;

}
