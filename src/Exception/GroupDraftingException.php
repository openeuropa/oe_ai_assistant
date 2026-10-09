<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use NeuronAI\Exceptions\AgentException;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * A field group that could not be drafted, with an editor-facing reason.
 *
 * The message never carries provider internals. An AI service failure ends
 * the drafting turn, a content failure is handed back to the model.
 */
final class GroupDraftingException extends \RuntimeException {

  public function __construct(string $label, \Throwable $cause) {
    parent::__construct(self::reason($label, $cause), 0, $cause);
  }

  /**
   * Whether the cause is a timeout, worth another attempt.
   */
  public static function isTimeout(\Throwable $e): bool {
    return !$e instanceof AgentException && (bool) preg_match('/\btime[ -]?(d )?out/i', $e->getMessage());
  }

  /**
   * Whether the AI service could not be reached at all.
   */
  public static function isUnreachable(\Throwable $e): bool {
    for ($cause = $e; $cause !== NULL; $cause = $cause->getPrevious()) {
      if ($cause instanceof NetworkExceptionInterface) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the cause is the AI service rather than the drafted content.
   */
  public function endsTheTurn(): bool {
    return !$this->getPrevious() instanceof AgentException;
  }

  /**
   * Describes the failure without the cause's internals.
   */
  private static function reason(string $label, \Throwable $e): string {
    if (self::isTimeout($e)) {
      return "The AI service did not respond in time while drafting $label.";
    }
    if (self::isUnreachable($e)) {
      return "The AI service could not be reached while drafting $label.";
    }
    if ($e instanceof AiRateLimitException) {
      return "The AI service is rate limited; try drafting $label again in a minute.";
    }
    if ($e instanceof AiQuotaException) {
      return "The AI service usage limit was reached while drafting $label.";
    }
    if ($e instanceof AgentException) {
      return "The AI service could not produce valid content for $label.";
    }
    return "The AI service returned an error while drafting $label.";
  }

}
