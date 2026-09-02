<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionType;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\system\SystemManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for AI editorial session pages.
 */
class AiEditorialSessionController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $sessionEntityTypeManager,
    private readonly SystemManager $systemManager,
    private readonly RequestStack $requestStack,
    private readonly AiAssistantPluginManager $pluginManager,
  ) {}

  /**
   * Displays the bundle selection page or redirects to the only bundle.
   *
   * @return array
   *   The render array to show in the page.
   */
  public function addPage(): array|RedirectResponse {
    $bundles = $this->sessionEntityTypeManager
      ->getStorage('ai_editorial_session_type')
      ->loadMultiple();

    if ($bundles === []) {
      throw new NotFoundHttpException();
    }
    uasort($bundles, static fn (AiEditorialSessionType $a, AiEditorialSessionType $b): int => strnatcasecmp($a->label(), $b->label()));
    if (count($bundles) === 1) {
      /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionType $bundle */
      $bundle = reset($bundles);
      return $this->redirect('entity.ai_editorial_session.add_form', [
        'ai_editorial_session_type' => $bundle->id(),
      ]);
    }
    $items = [];
    foreach ($bundles as $bundle) {
      $items[] = [
        '#type' => 'link',
        '#title' => $bundle->label(),
        '#url' => Url::fromRoute('entity.ai_editorial_session.add_form', [
          'ai_editorial_session_type' => $bundle->id(),
        ]),
      ];
    }

    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Select the type of AI editorial session to create.') . '</p>',
      ],
      'bundles' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * Displays the admin configuration parent page.
   *
   * @return array
   *   The render array to show in the page.
   */
  public function adminConfigPage(): array {
    return $this->systemManager->getBlockContents();
  }

  /**
   * Builds the render array for the AI Assistant in the session page.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $ai_editorial_session
   *   The entity to view.
   *
   * @return array
   *   The render array to show on the page.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    return $this->buildRenderArray($ai_editorial_session);
  }

  /**
   * Builds the common render array for the AI Assistant.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The AI editorial session the page is built for.
   *
   * @return array
   *   A Drupal render array with mount point, library, and settings.
   */
  private function buildRenderArray(AiEditorialSessionInterface $session): array {
    $sessionId = (string) $session->id();
    // Collect the per-plugin bootstrap configuration. Each plugin owns its
    // portion of the config and contributes its cacheable metadata, keeping
    // this controller plugin-agnostic.
    $cacheability = new CacheableMetadata();
    $enabledPlugins = array_keys($this->pluginManager->getDefinitions());
    // Discovery order is not guaranteed; sort for a deterministic output.
    sort($enabledPlugins);
    $pluginConfig = [];
    foreach ($enabledPlugins as $pluginId) {
      $config = $this->pluginManager->createInstance($pluginId)->getAppConfig($session, $cacheability);
      if ($config !== []) {
        $pluginConfig[$pluginId] = $config;
      }
    }
    // Build the configuration object that bootstraps the React app.
    // This data is serialised into window.drupalSettings.oeAiAssistant
    // and read by the React entry point before the first render.
    $config = [
      // Base URL for all REST API calls made by the React app.
      // getBasePath() returns the subdirectory prefix (empty string when
      // Drupal is installed at the domain root), guaranteeing correct paths
      // in both root and subdirectory installations.
      'apiBaseUrl' => $this->requestStack->getCurrentRequest()->getBasePath() . '/api/ai',
      // Current user ID as a string, available to the React app for
      // user-specific behaviour.
      'userId' => (string) $this->currentUser()->id(),
      // Display name of the current user, e.g. for message avatars.
      'userName' => (string) $this->currentUser()->getDisplayName(),
      // AI editorial session entity ID. It scopes the state the React
      // app persists in localStorage, so different sessions never share
      // frontend state while collaborating users on the same session do.
      'sessionId' => $sessionId,
      // Session title shown by the app shell header, prefixed with the
      // human readable bundle, e.g. "Content creation: March newsletter".
      'sessionTitle' => $this->sessionBundleLabel($session) . ': ' . $session->label(),
      // Where the exit control returns the editor to: the AI editorial
      // sessions dashboard.
      'exitUrl' => Url::fromRoute('entity.ai_editorial_session.collection')->toString(),
      // Disclaimer shown under the chat composer.
      'disclaimer' => (string) $this->t('AI assistant can make mistakes. Please double-check responses.'),
      // List of plugin IDs that should be available in the UI for this node.
      // The React app only registers plugins whose IDs appear in this list,
      // allowing server-side control over which tools are shown per context.
      'enabledPlugins' => $enabledPlugins,
      // Per-plugin configuration objects passed directly to each plugin's
      // frontend initialisation code. Only plugins whose getAppConfig()
      // returns a non-empty array have an entry here.
      'pluginConfig' => $pluginConfig,
    ];

    $build = [
      // The user context is needed because the settings embed the current
      // user id. Plugin-specific cacheability is merged in below.
      '#cache' => [
        'contexts' => ['user'],
      ],
      // The React mount point: a plain <div> with a stable ID that the
      // bundled React app locates via getElementById('oe-ai-assistant').
      // The data-ai-app attribute is a hook for automated tests.
      // The region-less session page template leaves the whole viewport
      // to the mount; the displacement variable subtracts whatever the
      // Drupal toolbar currently occupies at the top.
      'container' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'oe-ai-assistant',
          'data-ai-app' => TRUE,
          'style' => 'height: calc(100vh - var(--drupal-displace-offset-top, 0px)) !important;',
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

    // Invalidate the page when the session or its bundle changes (the
    // settings embed the session label and the bundle label), and merge in
    // whatever cacheability the plugins declared for their configuration.
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($session)
      ->addCacheableDependency($this->sessionEntityTypeManager->getStorage('ai_editorial_session_type')->load($session->bundle()))
      ->merge($cacheability)
      ->applyTo($build);

    return $build;
  }

  /**
   * Returns the human readable label of the session's bundle.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The AI editorial session.
   *
   * @return string
   *   The session type label, falling back to the bundle machine name.
   */
  private function sessionBundleLabel(AiEditorialSessionInterface $session): string {
    $bundle = $this->sessionEntityTypeManager
      ->getStorage('ai_editorial_session_type')
      ->load($session->bundle());

    return (string) ($bundle?->label() ?? $session->bundle());
  }

}
