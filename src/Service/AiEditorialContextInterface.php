<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Builds editorial audience and tone context for AI prompts.
 */
interface AiEditorialContextInterface {

  /**
   * Returns the available target audience terms.
   *
   * @return array<int, array{id: string, name: string, oe_ai_prompt: string}>
   *   The available audience terms keyed numerically.
   */
  public function getAvailableAudiences(): array;

  /**
   * Returns the available tone terms.
   *
   * @return array<int, array{id: string, name: string, oe_ai_prompt: string}>
   *   The available tone terms keyed numerically.
   */
  public function getAvailableTones(): array;

  /**
   * Builds the prompt for asking the user to select audience and tone.
   *
   * @return string
   *   System prompt for an agent listing all available audiences and tones.
   */
  public function buildSelectionPrompt(): string;

  /**
   * Builds the prompt for a selected audience and tone.
   *
   * @param string $audienceId
   *   The selected audience term ID.
   * @param string $toneId
   *   The selected tone term ID.
   *
   * @return string
   *   System prompt for an agent to apply.
   */
  public function buildSelectedPrompt(string $audienceId, string $toneId): string;

}
