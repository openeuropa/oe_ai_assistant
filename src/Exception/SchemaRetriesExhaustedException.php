<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown when no answer of a drafter matched the schema of its group.
 *
 * The message names the group and the violations of the last answer. It
 * reaches the model and the editor, so it carries nothing but those.
 */
class SchemaRetriesExhaustedException extends \RuntimeException {}
