<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Service\ManifestReader;
use Drupal\node\NodeInterface;
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
   * @param \Drupal\oe_ai_assistant\Service\ManifestReader $manifestReader
   *   Reads the Vite manifest to resolve hashed asset filenames for the
   *   compiled React bundle. Injected for potential use in asset resolution;
   *   the library attachment currently goes through Drupal's library system.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Provides access to the current HTTP request, used here to derive the
   *   application base path for constructing the API base URL. This ensures
   *   the React app points to the correct path even when Drupal is installed
   *   in a subdirectory (e.g. /mysite/api/ai rather than /api/ai).
   */
  public function __construct(
    private readonly ManifestReader $manifestReader,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Builds the render array for the AI Assistant tab on a node page.
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
    // Build the configuration object that bootstraps the React app.
    // This data is serialised into window.drupalSettings.oeAiAssistant
    // and read by the React entry point before the first render.
    $config = [
      // Base URL for all REST API calls made by the React app.
      // getBasePath() returns the subdirectory prefix (empty string when
      // Drupal is installed at the domain root), guaranteeing correct paths
      // in both root and subdirectory installations.
      'apiBaseUrl' => $this->requestStack->getCurrentRequest()->getBasePath() . '/api/ai',
      // Node ID as a string; the React app and OpenAPI schema treat all
      // entity IDs as strings for consistency across entity types.
      'nodeId' => (string) $node->id(),
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
          'entityTypeId' => $node->getEntityTypeId(),
          'bundle' => $node->bundle(),
        ],
      ],
    ];

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
