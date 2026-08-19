<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Theme hooks forcing the region-less page template on session pages.
 */
final class ThemeHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_theme().
   *
   * Registers the module-owned session page template so it is available
   * in the theme registry no matter which theme is active.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'page__ai_editorial_session' => [
        'base hook' => 'page',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_page_alter().
   *
   * Applies the region-less session template on the session route only.
   * Appended last, the suggestion has the highest priority and wins over
   * the active theme's page templates.
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions): void {
    if ($this->routeMatch->getRouteName() === 'entity.ai_editorial_session.canonical') {
      $suggestions[] = 'page__ai_editorial_session';
    }
  }

}
