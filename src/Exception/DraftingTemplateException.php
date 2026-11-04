<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * Thrown when a drafting template id cannot be resolved.
 *
 * The message names only the template id and why it did not resolve, so it
 * reaches the editor as-is. A plain \InvalidArgumentException is not safe
 * to show the same way: other callers in the createAgent() chain (provider
 * configuration, channel setup) throw that base class too, and their
 * messages can carry internals. Extending it keeps this exception catchable
 * wherever the base class already is, while callers that must not conflate
 * the two catch this subclass specifically.
 */
class DraftingTemplateException extends \InvalidArgumentException {}
