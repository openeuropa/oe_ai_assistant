<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown by plugin actions to signal an error to the controller.
 *
 * The controller catches this and returns it as a JSON error response
 * using the ApiError schema: { code, message }.
 */
class ActionException extends \RuntimeException {

  public function __construct(
    public readonly string $errorCode,
    string $message,
    public readonly int $statusCode = 400,
  ) {
    parent::__construct($message);
  }

}
