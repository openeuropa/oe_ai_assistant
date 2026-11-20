<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * A request to the document pager that names nothing it can serve.
 *
 * The message is written for the model that made the request, so a tool
 * hands it back as feedback.
 */
class DocumentPagerException extends \InvalidArgumentException {}
