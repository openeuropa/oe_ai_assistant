<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining an AI drafting template config entity.
 */
interface AiDraftingTemplateInterface extends ConfigEntityInterface {

  /**
   * Validates the template against Drupal field definitions.
   *
   * @return \Drupal\oe_ai_assistant\TemplateValidationResult
   *   The template validation result.
   */
  public function validate(): TemplateValidationResult;

  /**
   * Returns the defaults map with special tokens resolved.
   *
   * Supported tokens: __NOW__ → current Unix timestamp.
   *
   * @return array<string, mixed>
   *   The mapping with tokens resolved.
   */
  public function resolveDefaults(): array;

  /**
   * Returns the human-readable description.
   */
  public function getDescription(): string;

  /**
   * Returns the target node bundle machine name.
   */
  public function getContentType(): string;

  /**
   * Returns the ordered field definitions map.
   *
   * @return array<string, mixed>
   *   The ordered fields.
   */
  public function getFields(): array;

  /**
   * Returns the default values map.
   *
   * @return array<string, mixed>
   *   The defaults mapping.
   */
  public function getDefaults(): array;

}
