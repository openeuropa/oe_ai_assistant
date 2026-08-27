<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an unsaved entity as a complete, standalone themed HTML document.
 */
interface PreviewRendererInterface {

  /**
   * Renders the entity through the site's front-end theme, view mode "full".
   *
   * Never saves the entity. The returned response is a complete HTML
   * document (doctype, head with theme assets, body) suitable for loading
   * into an iframe.
   *
   * Side effect: forces the site's default theme as the active theme for
   * the remainder of the current request (see PreviewRenderer's class
   * docblock for why).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   The unsaved entity to render.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A text/html response.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\ActionException
   *   'render_failed' (500) if rendering throws.
   */
  public function render(ContentEntityInterface $node): Response;

}
