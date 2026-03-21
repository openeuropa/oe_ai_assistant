<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown by plugin actions to signal a structured error to the controller.
 *
 * The controller catches ActionException instances at the boundary
 * between the plugin layer and the HTTP layer. It maps the exception
 * properties to an ApiError JSON response body and sets the HTTP
 * status code accordingly.
 *
 * Example usage inside a plugin action:
 *
 * @code
 * throw new ActionException(
 *   errorCode: 'invalid_schema',
 *   message: 'The content schema is missing required fields.',
 *   statusCode: 422,
 * );
 * @endcode
 */
class ActionException extends \RuntimeException {

  /**
   * Constructs a new ActionException.
   *
   * @param string $errorCode
   *   A short, machine-readable error code included in the ApiError
   *   JSON response body under the "code" key (e.g. "invalid_schema",
   *   "stream_error"). Clients use this to distinguish error types
   *   without parsing the human-readable message.
   * @param string $message
   *   A human-readable description of the error. Passed to the parent
   *   RuntimeException and included in the JSON response body under
   *   the "message" key.
   * @param int $statusCode
   *   The HTTP status code the controller should return in the
   *   response. Defaults to 400 (Bad Request). Use 422 for validation
   *   errors, 500 for server-side failures, etc.
   */
  public function __construct(
    public readonly string $errorCode,
    string $message,
    public readonly int $statusCode = 400,
  ) {
    // Pass only the message to RuntimeException; errorCode and
    // statusCode are stored as typed readonly properties so the
    // controller can access them directly without parsing the message.
    parent::__construct($message);
  }

}
