<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Interface for the sub-agent orchestration service.
 *
 * Dispatches one sub-agent per schema group, streams plan/step
 * progress events, and consolidates results into a flat fields map.
 */
interface DraftingOrchestratorInterface {

  /**
   * Runs the sub-agent orchestration loop.
   *
   * Splits the schema, dispatches one sub-agent per group, emits
   * plan/step events, and returns the consolidated fields map.
   *
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The SSE stream for emitting plan and data events.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $history
   *   The conversation history for sub-agent context.
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The bundle machine name (e.g. "oe_news").
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity hosting the conversation (the editorial session).
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The draft_content turn each sub-agent turn nests under, or NULL to run
   *   without recording the sub-agent transcript.
   * @param string $templateId
   *   An ai_drafting_template id to restrict generation to, or '' for the full
   *   schema.
   *
   * @return array
   *   The consolidated fields map, or empty array if no fields
   *   were generated.
   */
  public function run(
    UiMessageStreamInterface $stream,
    array $history,
    string $entityTypeId,
    string $bundle,
    EntityInterface $host,
    ?AiConversationMessageInterface $parent = NULL,
    string $templateId = '',
  ): array;

}
