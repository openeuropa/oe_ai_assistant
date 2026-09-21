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
use Symfony\Component\HttpFoundation\Request;
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
    // Expose the previewed entity to the rest of the host site for the
    // remainder of this request (e.g. response subscribers).
    $request->attributes->set('oe_ai_assistant_preview_entity', $node);

    $this->logger->debug('Rendering preview of @type:@bundle (@state) in theme "@theme". Inline children: @children', [
      '@type' => $node->getEntityTypeId(),
      '@bundle' => $node->bundle(),
      '@state' => $node->isNew() ? 'unsaved' : 'node ' . $node->id(),
      '@theme' => $defaultTheme,
      '@children' => $this->summariseInlineChildren($node),
    ]);

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
      // Render errors arrive wrapped several exceptions deep, so the chain
      // is summarised ahead of the trace to put the real cause first.
      $this->logger->error('Failed to render preview of @type:@bundle. Cause: @chain Payload: @payload Trace: @exception', [
        '@type' => $node->getEntityTypeId(),
        '@bundle' => $node->bundle(),
        '@chain' => $this->formatExceptionChain($e),
        '@payload' => $this->readDraftPayload($request),
        '@exception' => (string) $e,
      ]);
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

  /**
   * Summarises the inline children hanging off an entity, for the log.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being previewed.
   *
   * @return string
   *   One segment per inline-entity field, naming each item's bundle in
   *   order, or a placeholder when the entity has no inline children.
   */
  private function summariseInlineChildren(ContentEntityInterface $entity): string {
    $segments = [];
    foreach ($entity->getFields() as $fieldName => $itemList) {
      if ($itemList->getFieldDefinition()->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $bundles = [];
      foreach ($itemList as $item) {
        $child = $item->entity;
        $bundles[] = $child instanceof ContentEntityInterface ? $child->bundle() : '<missing>';
      }
      if ($bundles !== []) {
        $segments[] = sprintf('%s: %d (%s)', $fieldName, count($bundles), implode(', ', $bundles));
      }
    }
    return $segments === [] ? '<none>' : implode('; ', $segments);
  }

  /**
   * Flattens an exception and everything it wraps into one line.
   *
   * @param \Throwable $exception
   *   The caught exception.
   *
   * @return string
   *   Each exception in the chain as class, message and origin, outermost
   *   first.
   */
  private function formatExceptionChain(\Throwable $exception): string {
    $links = [];
    for ($current = $exception; $current !== NULL; $current = $current->getPrevious()) {
      $links[] = sprintf(
        '%s: %s (%s:%d)',
        get_class($current),
        $current->getMessage(),
        $current->getFile(),
        $current->getLine(),
      );
    }
    return implode(' | caused by | ', $links);
  }

  /**
   * Reads back the merged draft payload DraftAssembler parked on the request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The payload as JSON, or a placeholder when the request carries none
   *   (a preview of an entity this module did not assemble).
   */
  private function readDraftPayload(Request $request): string {
    $payload = $request->attributes->get(DraftAssemblerInterface::PAYLOAD_REQUEST_ATTRIBUTE);
    if (!is_array($payload)) {
      return '<not recorded>';
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === FALSE ? '<payload could not be encoded>' : $json;
  }

}
