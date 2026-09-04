<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Render\MainContent\MainContentRendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders an unsaved entity as a complete, standalone themed HTML document.
 *
 * The active theme forced in render() is deliberately NOT restored
 * afterward. Drupal\Core\EventSubscriber\HtmlResponseSubscriber::onRespond()
 * runs on KernelEvents::RESPONSE, AFTER this method returns, and resolves
 * the response's CSS/JS libraries via HtmlResponseAttachmentsProcessor
 * against whatever theme is active at that later point. Restoring the
 * theme here would make that later step resolve assets against the wrong
 * (original) theme instead of the forced front-end theme. Each Drupal
 * request is a fresh process, so leaving the forced theme active for the
 * rest of this request is safe.
 */
class PreviewRenderer implements PreviewRendererInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ThemeInitializationInterface $themeInitialization,
    private readonly ThemeManagerInterface $themeManager,
    #[Autowire(service: 'main_content_renderer.html')]
    private readonly MainContentRendererInterface $htmlRenderer,
    private readonly RequestStack $requestStack,
    #[Autowire(service: 'current_route_match')]
    private readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(ContentEntityInterface $node): Response {
    // The flag core's own preview machinery uses, so formatters that check
    // it behave consistently.
    $node->in_preview = TRUE;

    // Force the site's front-end theme.
    $defaultTheme = $this->themeHandler->getDefault();
    $activeTheme = $this->themeInitialization->getActiveThemeByName($defaultTheme);
    $this->themeManager->setActiveTheme($activeTheme);

    $request = $this->requestStack->getCurrentRequest();

    // Render as an anonymous visitor: the iframe is meant to show what a
    // real site visitor would see, with no admin toolbar/chrome and no
    // access to fields the current (possibly privileged) editor can see but
    // the public can't. Always switched back in finally() - leaking the
    // anonymous session past this method would misattribute the rest of
    // the request to an unauthenticated user.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $build = $this->entityTypeManager
        ->getViewBuilder($node->getEntityTypeId())
        ->view($node, 'full');
      $build['#title'] = $node->label();
      // Do not render-cache an unsaved, possibly id-less entity.
      unset($build['#cache']);

      $response = $this->htmlRenderer->renderResponse($build, $request, $this->routeMatch);
      // The preview reflects transient draft state addressed by query
      // parameters the route cache contexts know nothing about. Mark the
      // response uncacheable, or the dynamic page cache would replay the
      // first rendered version for every later version of the session.
      if ($response instanceof CacheableResponseInterface) {
        $response->getCacheableMetadata()->setCacheMaxAge(0);
      }
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to render draft preview: @e', ['@e' => (string) $e]);
      throw new ActionException(
        'render_failed',
        'The draft could not be rendered as a preview. See the system log for details.',
        500,
      );
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

}
