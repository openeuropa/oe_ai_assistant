<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown when the tone a session stores does not resolve.
 *
 * A caller that catches \InvalidArgumentException also catches a
 * misconfigured provider or channel. That caller then sends the editor a
 * message that carries internals. This message names only the term id and
 * the reason it failed, which is safe for the editor to read. A caller
 * catches this subclass to separate the two cases.
 */
class DraftingToneException extends \InvalidArgumentException {}
