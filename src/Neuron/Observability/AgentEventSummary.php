<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Observability;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;

/**
 * Describes a Neuron event in one short line for the editor.
 */
final class AgentEventSummary {

  /**
   * Returns the line describing an event and its payload.
   */
  public static function describe(string $event, mixed $data): string {
    return match (TRUE) {
      $event === 'workflow-start' => 'run started',
      $event === 'workflow-end' => 'run finished',
      $data instanceof WorkflowNodeStart => 'node ' . self::shortName($data->node) . ' started',
      $data instanceof WorkflowNodeEnd => 'node ' . self::shortName($data->node) . ' finished',
      $event === 'inference-start' => 'model call started',
      $data instanceof InferenceStop => self::inference($data),
      $data instanceof ToolCalling => 'calling ' . $data->tool->getName() . self::inputs($data->tool->getInputs()),
      $data instanceof ToolCalled => self::toolResult($data),
      $data instanceof MessageSaving => 'saving ' . $data->message->getRole() . ' message',
      $data instanceof MessageSaved => 'saved ' . $data->message->getRole() . ' message',
      $data instanceof Validating => 'validating the answer against the ' . $data->class . ' schema',
      $data instanceof Validated => $data->violations === []
        ? 'answer matches the ' . $data->class . ' schema'
        : sprintf('answer rejected, %d schema violation(s)', count($data->violations)),
      $data instanceof AgentError => 'error: ' . $data->exception->getMessage(),
      default => str_replace('-', ' ', $event),
    };
  }

  /**
   * Describes the model's answer: text or tool requests, with the tokens.
   */
  private static function inference(InferenceStop $data): string {
    $response = $data->response;
    $summary = $response instanceof ToolCallMessage
      ? 'model requested ' . implode(', ', array_map(static fn ($tool) => $tool->getName(), $response->getTools()))
      : 'model answered';
    $usage = $response->getUsage();
    if ($usage !== NULL) {
      $summary .= sprintf(' (%d in, %d out tokens)', $usage->inputTokens, $usage->outputTokens);
    }
    return $summary;
  }

  /**
   * Describes a finished tool call, naming a failure the tool reported.
   */
  private static function toolResult(ToolCalled $data): string {
    $result = json_decode($data->tool->getResult(), TRUE);
    if (is_array($result) && isset($result['error'])) {
      return $data->tool->getName() . ' failed: ' . $result['error'];
    }
    return $data->tool->getName() . ' returned';
  }

  /**
   * Renders tool inputs as a compact suffix, or nothing without inputs.
   */
  private static function inputs(array $inputs): string {
    return $inputs === [] ? '' : ' ' . json_encode($inputs, JSON_UNESCAPED_SLASHES);
  }

  /**
   * Returns the class name without its namespace.
   */
  private static function shortName(string $class): string {
    return substr($class, (int) strrpos($class, '\\') + 1);
  }

}
