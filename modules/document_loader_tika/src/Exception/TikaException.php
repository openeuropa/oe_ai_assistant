<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Exception;

/**
 * Thrown when the Tika server cannot be reached or returns no usable text.
 */
final class TikaException extends \RuntimeException {}
