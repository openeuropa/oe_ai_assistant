<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining an AI drafting template config entity.
 */
interface AiDraftingTemplateInterface extends ConfigEntityInterface {

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
   */
  public function getFields(): array;

  /**
   * Returns the default values map.
   *
   * @return array<string, mixed>
   */
  public function getDefaults(): array;

}
