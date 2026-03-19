<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\ManifestReader;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the AI Assistant tab on node pages.
 *
 * Provides a mount point div and passes configuration to the React app
 * via drupalSettings.
 */
class NodeTabController extends ControllerBase {

  public function __construct(
    private readonly ManifestReader $manifestReader,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Renders the AI Assistant page for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity (upcasted from the route parameter).
   *
   * @return array
   *   A render array with the mount point and attached JS.
   */
  public function content(NodeInterface $node): array {
    $config = [
      'apiBaseUrl' => $this->requestStack->getCurrentRequest()->getBasePath() . '/api/ai',
      'nodeId' => (string) $node->id(),
      'userId' => (string) $this->currentUser()->id(),
      'enabledPlugins' => ['echo', 'notes', 'drafting'],
      'pluginConfig' => [
        'drafting' => [
          'entityTypeId' => $node->getEntityTypeId(),
          'bundle' => $node->bundle(),
        ],
      ],
    ];

    return [
      'container' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'oe-ai-assistant',
          'data-ai-app' => TRUE,
          'style' => 'height: calc(100vh - 250px) !important;',
        ],
      ],
      '#attached' => [
        'library' => ['oe_ai_assistant/app'],
        'drupalSettings' => [
          'oeAiAssistant' => $config,
        ],
      ],
    ];
  }

}
