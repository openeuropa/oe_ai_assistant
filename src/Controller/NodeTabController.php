<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the AI Assistant tab on node entity pages.
 *
 * Outputs a render array containing:
 *   - A <div id="oe-ai-assistant"> mount point targeted by the React app.
 *   - The oe_ai_assistant/app Drupal library (the compiled IIFE bundle).
 *   - drupalSettings.oeAiAssistant configuration consumed by the React app
 *     on bootstrap to know which node to operate on and which plugins are
 *     available.
 */
class NodeTabController extends ControllerBase {

  /**
   * Constructs a NodeTabController instance.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Provides access to the current HTTP request, used here to derive the
   *   application base path for constructing the API base URL. This ensures
   *   the React app points to the correct path even when Drupal is installed
   *   in a subdirectory (e.g. /mysite/api/ai rather than /api/ai).
   */
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds the render array for the AI Assistant tab on an existing node.
   *
   * Assembles the drupalSettings configuration payload and returns a render
   * array that Drupal's theme layer will convert into an HTML page fragment.
   * The React bundle, once loaded, finds the mount div by its ID attribute
   * and mounts the full assistant UI into it.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity resolved from the {node} route parameter by Drupal's
   *   entity upcasting mechanism. Provides node ID, entity type, and bundle
   *   used to populate the configuration passed to the React app.
   *
   * @return array
   *   A Drupal render array with the following keys:
   *     - "container": an html_tag element rendering the React mount point.
   *     - "#attached": library and drupalSettings attachments for the page.
   */
  public function content(NodeInterface $node): array {
    return $this->buildRenderArray(
      $node->getEntityTypeId(),
      $node->bundle(),
      (string) $node->id(),
    );
  }

  /**
   * Builds the render array for the AI Assistant tab on a node add page.
   *
   * Works like content() but for new nodes that do not yet have an ID.
   * The nodeId is omitted from the configuration since the node has not been
   * created yet. The drafting plugin receives entity type and bundle from the
   * node type so it can still fetch the correct content schema.
   *
   * @param \Drupal\node\NodeTypeInterface $node_type
   *   The node type entity resolved from the {node_type} route parameter.
   *
   * @return array
   *   A Drupal render array identical in structure to content().
   */
  public function addContent(NodeTypeInterface $node_type): array {
    return $this->buildRenderArray('node', $node_type->id());
  }

  /**
   * Builds the common render array for the AI Assistant.
   *
   * Centralises the render array construction shared by both the existing-node
   * tab (content()) and the node-add tab (addContent()). The only difference
   * is whether a nodeId is present in the configuration payload.
   *
   * @param string $entityTypeId
   *   The entity type ID (always 'node' for now).
   * @param string $bundle
   *   The content type machine name (e.g. 'article', 'page').
   * @param string|null $nodeId
   *   The node ID as a string, or NULL when creating a new node.
   *
   * @return array
   *   A Drupal render array with mount point, library, and settings.
   */
  private function buildRenderArray(string $entityTypeId, string $bundle, ?string $nodeId = NULL): array {
    // Build the configuration object that bootstraps the React app.
    // This data is serialised into window.drupalSettings.oeAiAssistant
    // and read by the React entry point before the first render.
    $config = [
      // Base URL for all REST API calls made by the React app.
      // getBasePath() returns the subdirectory prefix (empty string when
      // Drupal is installed at the domain root), guaranteeing correct paths
      // in both root and subdirectory installations.
      'apiBaseUrl' => $this->requestStack->getCurrentRequest()->getBasePath() . '/api/ai',
      // Current user ID as a string, used by the React app to namespace
      // per-user state (e.g. conversation history keys in TempStore).
      'userId' => (string) $this->currentUser()->id(),
      // List of plugin IDs that should be available in the UI for this node.
      // The React app only registers plugins whose IDs appear in this list,
      // allowing server-side control over which tools are shown per context.
      'enabledPlugins' => ['echo', 'notes', 'drafting'],
      // Per-plugin configuration objects passed directly to each plugin's
      // frontend initialisation code. Only plugins that require extra context
      // need an entry here.
      'pluginConfig' => [
        // The drafting plugin needs to know which entity type and bundle it
        // is operating on so it can request the correct content schema and
        // build appropriately scoped AI prompts.
        'drafting' => [
          'entityTypeId' => $entityTypeId,
          'bundle' => $bundle,
        ],
      ],
    ];

    // Include the node ID only when editing an existing node. When creating
    // a new node the ID does not exist yet, so it is omitted entirely rather
    // than set to null -- the React app treats an absent nodeId as a signal
    // that it is operating in creation mode.
    if ($nodeId !== NULL) {
      $config['nodeId'] = $nodeId;
    }

    return [
      // The React mount point: a plain <div> with a stable ID that the
      // bundled React app locates via getElementById('oe-ai-assistant').
      // The data-ai-app attribute is a hook for automated tests.
      // The inline height style reserves viewport space for the assistant
      // panel below Drupal's toolbar and node action buttons (~250px).
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
        // Attach the compiled React IIFE bundle. The library is defined in
        // oe_ai_assistant.libraries.yml and resolves asset paths via the
        // Vite manifest in app/dist/.
        'library' => ['oe_ai_assistant/app'],
        // Expose the configuration under the oeAiAssistant namespace so the
        // React entry point can read it from window.drupalSettings.
        'drupalSettings' => [
          'oeAiAssistant' => $config,
        ],
      ],
    ];
  }

}
