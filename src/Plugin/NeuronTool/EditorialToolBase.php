<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Tools\NeuronToolPluginBase;

/**
 * Base class for the tools of the editorial agent.
 */
abstract class EditorialToolBase extends NeuronToolPluginBase {

  /**
   * Encodes what a call answers as JSON.
   *
   * Plain json_encode() returns FALSE on bytes that are not UTF-8, which
   * content drafted by a model can carry. Returned as is, that breaks the
   * string return type a tool declares, and the agent then reports a
   * failure for work the call has already done. Substituting the bad bytes
   * keeps the answer accurate.
   *
   * @param array $result
   *   What the call answers.
   *
   * @return string
   *   The answer as JSON.
   */
  protected function encode(array $result): string {
    return json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  }

}
