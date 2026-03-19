<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches API requests to the appropriate AI Assistant plugin action.
 *
 * A single route handles all plugin actions:
 *   POST /api/ai/plugins/{plugin_id}/{action}
 *
 * The controller validates the plugin exists, the action is available,
 * and the request body matches the schema before dispatching.
 */
class PluginController extends ControllerBase {

  public function __construct(
    private readonly AiAssistantPluginManager $pluginManager,
    private readonly RequestValidator $requestValidator,
  ) {}

  /**
   * Dispatches a plugin action request.
   *
   * @param string $plugin_id
   *   The plugin ID from the URL.
   * @param string $action
   *   The action name from the URL.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function dispatch(string $plugin_id, string $action, Request $request): Response {
    // 1. Check the plugin exists.
    if (!$this->pluginManager->hasDefinition($plugin_id)) {
      return new JsonResponse(
        ['code' => 'not_found', 'message' => "Plugin '{$plugin_id}' not found."],
        404,
      );
    }

    // 2. Create the plugin instance.
    $plugin = $this->pluginManager->createInstance($plugin_id);

    // 3. Check the action exists on this plugin.
    if (!isset($plugin->getActionMap()[$action])) {
      return new JsonResponse(
        ['code' => 'not_found', 'message' => "Action '{$action}' not found on plugin '{$plugin_id}'."],
        404,
      );
    }

    // 4. Validate request body against the plugin's schema for this action.
    $schemas = $plugin->getRequestSchemas();
    if (isset($schemas[$action])) {
      $errors = $this->requestValidator->validateRaw($request->getContent(), $schemas[$action]);
      if (!empty($errors)) {
        return new JsonResponse([
          'code' => 'bad_request',
          'message' => implode('; ', $errors),
        ], 400);
      }
    }

    // 5. Dispatch to the plugin.
    try {
      $result = $plugin->executeAction($action, $request);
    }
    catch (ActionException $e) {
      return new JsonResponse(
        ['code' => $e->errorCode, 'message' => $e->getMessage()],
        $e->statusCode,
      );
    }

    // 6. If the plugin returned a Response (e.g. SSE stream), pass through.
    if ($result instanceof Response) {
      return $result;
    }

    // 7. Wrap array result in a JsonResponse.
    return new JsonResponse($result);
  }

}
