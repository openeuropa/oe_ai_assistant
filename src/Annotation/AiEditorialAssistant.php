<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Annotation;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Defines an AI Editorial Assistant plugin attribute.
 *
 * Plugin classes use this attribute to declare themselves as AI
 * Editorial Assistant plugins. The plugin manager discovers them
 * automatically via Drupal's attribute plugin discovery mechanism.
 *
 * Usage example on a plugin class:
 *
 * @code
 * #[AiEditorialAssistant(
 *   id: 'drafting',
 *   label: 'Drafting',
 *   description: 'AI-powered content drafting with streaming.',
 * )]
 * class DraftingPlugin extends AiAssistantPluginBase { ... }
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AiEditorialAssistant extends Plugin {

  /**
   * Constructs a new AiEditorialAssistant attribute instance.
   *
   * Called automatically by PHP when the attribute is read from a
   * class declaration. The parent Plugin::__construct() stores $id
   * on the base class so the plugin manager can look up plugins by
   * their identifier.
   *
   * @param string $id
   *   The unique machine name for this plugin (e.g. "drafting",
   *   "echo", "notes"). Must match the plugin ID used in API routes
   *   and frontend plugin registry entries.
   * @param string $label
   *   A human-readable label displayed in administrative UIs.
   * @param string|null $description
   *   An optional short description of what the plugin does. Shown
   *   in administrative overviews. Defaults to NULL if omitted.
   */
  public function __construct(
    string $id,
    public readonly string $label,
    public readonly ?string $description = NULL,
  ) {
    // Delegate ID storage to the parent Plugin attribute class.
    // The plugin manager reads $this->id via Plugin::getId().
    parent::__construct(id: $id);
  }

}
