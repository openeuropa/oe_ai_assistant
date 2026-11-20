<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown when the job that drafts a field group ended without fields.
 *
 * The message is the error the job recorded, which is safe for the editor.
 */
class DraftGroupFailedException extends \RuntimeException {}
