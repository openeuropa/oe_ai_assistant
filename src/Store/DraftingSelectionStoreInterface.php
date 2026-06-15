<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

/**
 * Stores selected audience and tone IDs for a drafting context.
 */
interface DraftingSelectionStoreInterface {

  /**
   * Loads the selected ID for an option type.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The drafting context.
   * @param string $optionType
   *   The option type: "audience" or "tone".
   *
   * @return string|null
   *   The stored selected term ID, if present.
   */
  public function load(array $context, string $optionType): ?string;

  /**
   * Stores the selected ID for an option type.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The drafting context.
   * @param string $optionType
   *   The option type: "audience" or "tone".
   * @param string $selectedId
   *   The selected term ID.
   */
  public function save(array $context, string $optionType, string $selectedId): void;

  /**
   * Drops the selected ID for an option type.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The drafting context.
   * @param string $optionType
   *   The option type: "audience" or "tone".
   */
  public function delete(array $context, string $optionType): void;

}
