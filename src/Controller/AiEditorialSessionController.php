<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for AI editorial session pages.
 */
class AiEditorialSessionController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $sessionEntityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Displays the bundle selection page or redirects to the only bundle.
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
   */
  public function adminConfigPage(): array {
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Configuration pages for the AI Editorial Assistant will appear here.') . '</p>',
      ],
    ];
  }

  /**
   * Displays the session placeholder page.
   */
  public function view(AiEditorialSessionInterface $ai_editorial_session): array {
    $items = [
      $this->t('Session ID: @id', ['@id' => $ai_editorial_session->id()]),
      $this->t('Bundle: @bundle', ['@bundle' => $ai_editorial_session->bundle()]),
      $this->t('Content type: @content_type', [
        '@content_type' => $ai_editorial_session->get('content_type')->value ?? '',
      ]),
      $this->t('Status: @status', ['@status' => $ai_editorial_session->getStatus()]),
    ];

    return [
      '#type' => 'container',
      'intro' => [
        '#markup' => '<p>' . $this->t('Session placeholder page.') . '</p>',
      ],
      'details' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

}
