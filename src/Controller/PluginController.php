<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches plugin action requests to the correct AI Assistant plugin.
 *
 * A single Drupal route maps to this controller for every plugin and action
 * combination. The controller is intentionally thin: it enforces the shared
 * validation contract and then delegates all domain logic to the individual
 * plugin implementations.
 *
 * Error responses follow a consistent JSON structure:
 *   { "code": "<machine_code>", "message": "<human_readable>" }
 *
 * Possible HTTP status codes returned by this controller:
 *   - 200 / plugin-defined: successful action execution.
 *   - 400 Bad Request: request body failed JSON Schema validation.
 *   - 404 Not Found: plugin or action does not exist.
 *   - 4xx/5xx: ActionException thrown by the plugin and mapped here.
 */
class PluginController extends ControllerBase {

  /**
   * Constructs a PluginController instance.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager $pluginManager
   *   The plugin manager for AI Assistant plugins. Used to check whether a
   *   plugin ID is registered (hasDefinition) and to create plugin instances
   *   (createInstance). Plugins are discovered from annotated classes under
   *   src/Plugin/AiEditorialAssistant/.
   * @param \Drupal\oe_ai_assistant\Service\RequestValidator $requestValidator
   *   Validates raw JSON request bodies against JSON Schema definitions
   *   provided by each plugin. Returns an array of human-readable error
   *   strings; an empty array means the body is valid.
   */
  public function __construct(
    private readonly AiAssistantPluginManager $pluginManager,
    private readonly RequestValidator $requestValidator,
  ) {}

  /**
   * Validates and dispatches a plugin action request.
   *
   * Executes the five-step validation and dispatch pipeline described in the
   * file-level docblock. Returns a structured JSON error at the first failing
   * step so that API clients receive a predictable error shape regardless of
   * which step failed.
   *
   * @param string $plugin_id
   *   The machine name of the target plugin (e.g. "drafting", "echo",
   *   "notes"). Sourced from the {plugin_id} route placeholder.
   * @param string $action
   *   The action to execute on the plugin (e.g. "chat", "list", "save").
   *   Sourced from the {action} route placeholder.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request. The request body must be valid JSON when the
   *   plugin provides a schema for the requested action.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   One of:
   *     - The plugin's own Response object (e.g. a StreamedResponse for SSE).
   *     - A JsonResponse wrapping the plugin's array return value (200).
   *     - A JsonResponse describing the error (400, 404, or plugin-defined).
   */
  public function dispatch(string $plugin_id, string $action, Request $request): Response {
    // Step 1: Confirm the plugin ID is registered with the plugin manager.
    // hasDefinition() inspects the plugin discovery cache; it does not
    // instantiate the plugin, keeping this check cheap.
    if (!$this->pluginManager->hasDefinition($plugin_id)) {
      return new JsonResponse(
        ['code' => 'not_found', 'message' => "Plugin '{$plugin_id}' not found."],
        404,
      );
    }

    // Step 2: Instantiate the plugin. createInstance() invokes the plugin's
    // constructor and injects any declared service dependencies. The plugin
    // instance is used for the remaining steps of this request only.
    $plugin = $this->pluginManager->createInstance($plugin_id);

    // Step 3: Verify the requested action is available on this plugin.
    // getActionMap() returns an associative array keyed by action name; the
    // values are the internal method names or callables that implement each
    // action. Using isset() avoids triggering any lazy-loading side effects.
    if (!isset($plugin->getActionMap()[$action])) {
      return new JsonResponse(
        ['code' => 'not_found', 'message' => "Action '{$action}' not found on plugin '{$plugin_id}'."],
        404,
      );
    }

    // Step 4: Validate the request body against the plugin's JSON Schema for
    // this action, if one is provided. Plugins that accept no body or impose
    // no constraints simply omit the action key from getRequestSchemas().
    $schemas = $plugin->getRequestSchemas();
    if (isset($schemas[$action])) {
      // validateRaw() parses the raw body string and checks it against the
      // JSON Schema object. It returns an array of human-readable error
      // strings, or an empty array when the body is valid.
      $rawBody = $request->getContent();
      if (str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')) {
        $rawBody = json_encode(
          $request->request->all() + $this->normalizeUploadedFiles($request->files->all()),
          JSON_THROW_ON_ERROR,
        );
      }

      $errors = $this->requestValidator->validateRaw($rawBody, $schemas[$action]);
      if (!empty($errors)) {
        // Flatten all validation error messages into a single semicolon-
        // separated string so the response remains a flat JSON object
        // rather than a nested error array.
        return new JsonResponse([
          'code' => 'bad_request',
          'message' => implode('; ', $errors),
        ], 400);
      }
    }

    // Step 5: Delegate execution to the plugin. executeAction() looks up the
    // action in the action map and invokes the corresponding handler. Any
    // business-logic error the plugin wants to surface as an HTTP error should
    // be thrown as an ActionException; other exceptions are left to propagate
    // up to Drupal's exception handling layer.
    try {
      $result = $plugin->executeAction($action, $request);
    }
    catch (ActionException $e) {
      // ActionException carries a structured error code and an HTTP status
      // code so the controller can produce a well-formed error response
      // without knowing the plugin's domain semantics.
      return new JsonResponse(
        ['code' => $e->errorCode, 'message' => $e->getMessage()],
        $e->statusCode,
      );
    }

    // Step 6a: If the plugin returned a Symfony Response object directly
    // (the typical case for SSE streaming actions that build a
    // StreamedResponse), pass it through unchanged so headers and the
    // streamed body are preserved exactly as the plugin set them up.
    if ($result instanceof Response) {
      return $result;
    }

    // Step 6b: For plugins that return a plain PHP array (e.g. CRUD actions
    // that produce a JSON payload), wrap the array in a JsonResponse with
    // the default 200 status code.
    return new JsonResponse($result);
  }

  /**
   * Converts uploaded files into scalar values for request validation.
   *
   * Symfony stores uploaded files outside the regular request parameter bag,
   * but multipart schemas still declare those fields as part of the request
   * shape. The plugin receives the original UploadedFile instances from the
   * request; this normalized copy is only used by JSON Schema validation.
   *
   * @param array<string, mixed> $files
   *   Uploaded files from the request file bag.
   *
   * @return array<string, mixed>
   *   File fields represented by their client filenames.
   */
  private function normalizeUploadedFiles(array $files): array {
    $normalized = [];
    foreach ($files as $key => $file) {
      if ($file instanceof UploadedFile) {
        $normalized[$key] = $file->getClientOriginalName();
      }
      elseif (is_array($file)) {
        $normalized[$key] = $this->normalizeUploadedFiles($file);
      }
    }

    return $normalized;
  }

}
