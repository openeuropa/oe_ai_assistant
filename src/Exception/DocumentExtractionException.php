<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Public-safe failure of document text extraction or summarisation.
 *
 * The message never carries provider or server internals; the cause is
 * chained for the log.
 */
class DocumentExtractionException extends \RuntimeException {}
