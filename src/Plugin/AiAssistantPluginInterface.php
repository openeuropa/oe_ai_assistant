<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface for AI Assistant plugins.
 *
 * Each plugin provides one or more actions that the PluginController
 * dispatches to via the URL path /api/ai/plugins/{plugin_id}/{action}.
 */
interface AiAssistantPluginInterface extends PluginInspectionInterface {

  /**
   * Returns the explicit map of URL action names to callables.
   *
   * Only actions listed here are reachable from the API. Each callable
   * accepts a Request and returns either:
   *   - An array: the controller wraps it in a JsonResponse.
   *   - A Response: passed through as-is (e.g. for SSE streaming).
   *
   * Use first-class callable syntax ($this->method(...)) to ensure
   * compile-time safety.
   *
   * @return array<string, \Closure(Request): (array|Response)>
   */
  public function getActionMap(): array;

  /**
   * Returns the request schema map for this plugin.
   *
   * Maps action names to schema names from dist/schemas.json. The
   * controller uses this to validate request bodies before dispatching.
   * Actions not listed here skip validation.
   *
   * @return array<string, string>
   *   Keys are action names, values are schema names.
   */
  public function getRequestSchemas(): array;

}
