<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionType;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\system\SystemManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for AI editorial session pages.
 */
class AiEditorialSessionController extends ControllerBase {

  public function __construct(
    private readonly AiEditorialContextInterface $aiEditorialContext,
    private readonly EntityTypeManagerInterface $sessionEntityTypeManager,
    private readonly SystemManager $systemManager,
    private readonly RequestStack $requestStack,
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
    return $this->buildRenderArray(
      (string) $ai_editorial_session->id(),
      'node',
      $ai_editorial_session->get('content_type')->target_id,
    );
  }

  /**
   * Builds the common render array for the AI Assistant.
   *
   * @param string $sessionId
   *   The AI editorial session entity ID.
   * @param string $entityTypeId
   *   The entity type ID (always 'node' for now).
   * @param string $bundle
   *   The content type machine name (e.g. 'article', 'page').
   *
   * @return array
   *   A Drupal render array with mount point, library, and settings.
   */
  private function buildRenderArray(string $sessionId, string $entityTypeId, string $bundle): array {
    $audiences = $this->serializeContextOptions($this->aiEditorialContext->getAvailableAudiences());
    $tones = $this->serializeContextOptions($this->aiEditorialContext->getAvailableTones());
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
      // AI editorial session entity ID. It scopes the state the React
      // app persists in localStorage, so different sessions never share
      // frontend state while collaborating users on the same session do.
      'sessionId' => $sessionId,
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
          'context' => [
            'audience' => $audiences,
            'tone' => $tones,
          ],
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

  /**
   * Serializes internal prompt-ready context options for frontend bootstrap.
   *
   * @param array<int, array{id: string, label: string, description: string, oe_ai_prompt: string}> $options
   *   The prompt-ready service options.
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   Frontend-safe context options.
   */
  private function serializeContextOptions(array $options): array {
    return array_map(
      static fn (array $option): array => [
        'id' => $option['id'],
        'label' => $option['label'],
        'description' => $option['description'],
      ],
      $options,
    );
  }

}
