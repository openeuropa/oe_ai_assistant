<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Builds editorial tone context for AI prompts.
 */
interface AiEditorialContextInterface {

  /**
   * Returns the available tone terms.
   *
   * @return array<int, array{id: string, label: string, description: string, oe_ai_prompt: string}>
   *   The available tone terms keyed numerically.
   */
  public function getAvailableTones(): array;

  /**
   * Builds the prompt for asking the user to select a tone.
   *
   * @return string
   *   System prompt for an agent listing all available tones.
   */
  public function buildSelectionPrompt(): string;

  /**
   * Builds the prompt for a selected tone.
   *
   * @param string $toneId
   *   The selected tone term ID.
   *
   * @return string
   *   System prompt for an agent to apply.
   */
  public function buildSelectedPrompt(string $toneId): string;

  /**
   * Resolves one prompt-ready tone term.
   *
   * @param string $toneId
   *   The taxonomy term ID.
   *
   * @return array{id: string, label: string, prompt: string}
   *   The tone id, label and raw prompt guidelines.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the term does not exist, belongs to another vocabulary,
   *   or has no prompt defined.
   */
  public function getTone(string $toneId): array;

}
