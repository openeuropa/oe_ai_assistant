<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Defines the contract for all AI Assistant plugins.
 *
 * An AI Assistant plugin encapsulates one functional area of the assistant
 * (for example, content drafting or note-taking). It exposes a set of named
 * "actions" that the REST API controller dispatches to when a request arrives
 * at /api/ai/plugins/{plugin_id}/{action}.
 *
 * Implementations must also extend PluginBase (or AiAssistantPluginBase) so
 * that Drupal's plugin system can discover and instantiate them. The plugin
 * ID and label are declared via the AiEditorialAssistant PHP attribute on
 * the concrete class.
 *
 * Action return types:
 *   - array: the controller serialises this to a JSON response automatically.
 *   - Response: passed through unchanged; use this for SSE streaming.
 *
 * Request validation:
 *   The controller calls getRequestSchemas() before dispatching. If the
 *   requested action maps to a schema name, the request body is validated
 *   against that schema (from dist/schemas.json) and a 422 response is
 *   returned on failure. Actions not listed skip validation.
 *
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginBase
 * @see \Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager
 * @see \Drupal\oe_ai_assistant\Annotation\AiEditorialAssistant
 */
interface AiAssistantPluginInterface extends PluginInspectionInterface {

  /**
   * Returns the explicit map of URL action names to callables.
   *
   * Only actions listed in this map are reachable from the API. Any action
   * name not present here will be rejected by executeAction() with a
   * PluginException, which the controller converts to a 404 response.
   *
   * Each callable must accept a single Request argument and return either
   * an array (serialised to JSON) or a Symfony Response (passed through
   * as-is). Using first-class callable syntax ($this->method(...)) is
   * strongly preferred over anonymous closures because it provides
   * compile-time safety and better IDE support.
   *
   * Example implementation:
   * @code
   * public function getActionMap(): array {
   *   return [
   *     'chat' => $this->handleChat(...),
   *     'status' => $this->handleStatus(...),
   *   ];
   * }
   * @endcode
   *
   * @return array<string, \Closure(\Symfony\Component\HttpFoundation\Request): (array|\Symfony\Component\HttpFoundation\Response)>
   *   A map of action name => callable.
   */
  public function getActionMap(): array;

  /**
   * Returns the request schema map for this plugin.
   *
   * Maps action names to schema identifiers from dist/schemas.json. Before
   * the controller dispatches a request, it looks up the action name in this
   * map. If a matching schema name is found, the request body is validated
   * against the compiled JSON Schema. A validation failure yields a 422
   * Unprocessable Entity response with a description of the errors.
   *
   * Actions that do not require a structured body (for example, simple GET-
   * style queries with no parameters) should be omitted from this map so
   * that no validation is attempted.
   *
   * Example implementation:
   * @code
   * public function getRequestSchemas(): array {
   *   return [
   *     'chat' => 'DraftingChatRequest',
   *   ];
   * }
   * @endcode
   *
   * @return array<string, string>
   *   Keys are action names, values are schema identifiers (as they appear
   *   as top-level keys inside dist/schemas.json).
   */
  public function getRequestSchemas(): array;

  /**
   * Returns the plugin's portion of the frontend bootstrap configuration.
   *
   * The session page controller assembles the pluginConfig object passed to
   * the React app by collecting this method's result from every plugin, so
   * the controller stays plugin-agnostic. Plugins that need no bootstrap
   * configuration return an empty array, which the controller omits.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The editorial session the page is built for.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   The page cacheability. Plugins whose configuration depends on other
   *   data (config entities, vocabularies) must add the matching cache tags
   *   and contexts here.
   *
   * @return array<string, mixed>
   *   The plugin configuration, serialised into drupalSettings under
   *   pluginConfig.{plugin_id}.
   */
  public function getAppConfig(AiEditorialSessionInterface $session, RefinableCacheableDependencyInterface $cacheability): array;

}
