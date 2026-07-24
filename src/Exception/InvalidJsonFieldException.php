<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

use Drupal\Core\Entity\EntityStorageException;

/**
 * Thrown when a JSON-backed conversation message field holds invalid data.
 *
 * Extends EntityStorageException so it is caught by code that already handles
 * storage-layer failures. Raised when an array cannot be encoded to JSON, when
 * a stored value cannot be decoded, or when saving a row whose JSON field holds
 * malformed data.
 */
class InvalidJsonFieldException extends EntityStorageException {

}
