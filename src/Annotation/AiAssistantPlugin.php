<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Annotation;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines an AI Assistant plugin attribute.
 *
 * Plugin classes use this attribute to declare themselves as AI Assistant
 * plugins. The plugin manager discovers them automatically.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AiAssistantPlugin extends Plugin {

  /**
   * @param string $id
   *   The plugin ID.
   * @param string $label
   *   Human-readable label for the plugin.
   * @param string|null $description
   *   Optional description.
   */
  public function __construct(
    string $id,
    public readonly string $label,
    public readonly ?string $description = NULL,
  ) {
    parent::__construct(id: $id);
  }

}
