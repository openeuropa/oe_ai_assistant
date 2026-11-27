<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Exception;

/**
 * A tool refusing a call, with a message the model is meant to read.
 *
 * The tool error handler keeps exception messages away from the model,
 * because a driver or a filesystem puts paths and queries in them and the
 * agent repeats to the editor whatever it is given. A refusal is different:
 * it says what the call got wrong, so the model can correct it and call
 * again. Only throw this with a message written for that reader.
 */
final class ToolRefusalException extends \RuntimeException {}
