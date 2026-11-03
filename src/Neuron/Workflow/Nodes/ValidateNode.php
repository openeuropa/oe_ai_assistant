<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Workflow\Nodes;

use Drupal\oe_ai_assistant\Neuron\Workflow\Events\DraftedEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Events\SchemaViolationEvent;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Checks a drafter answer against the schema of its group.
 */
final class ValidateNode extends Node {

  /**
   * The state key holding the field values of a matching answer.
   */
  public const string FIELDS_KEY = 'fields';

  /**
   * Builds the node.
   *
   * @param string $group
   *   The field group the answer is for.
   * @param array $schema
   *   The JSON schema to check against, with its required list closed.
   */
  public function __construct(
    private readonly string $group,
    private readonly array $schema,
  ) {}

  /**
   * Stores a matching answer, or reports what it breaks.
   *
   * Either way this node reports the check, under the name Neuron gives the
   * same check in its own structured output node. A listener of the run then
   * sees which group the node drafted for and what the answer missed.
   */
  public function __invoke(DraftedEvent $event, WorkflowState $state): StopEvent|SchemaViolationEvent {
    $json = (new JsonExtractor())->getJson($event->answer);
    $violations = $json === NULL ? ['The answer holds no JSON object.'] : $this->violations($json);
    $this->emit(new Validated($this->group, (string) $json, $violations));

    if ($violations === []) {
      $state->set(self::FIELDS_KEY, (array) json_decode((string) $json, TRUE));

      return new StopEvent();
    }

    return new SchemaViolationEvent($violations);
  }

  /**
   * What a JSON answer breaks in the schema, empty when it matches.
   */
  private function violations(string $json): array {
    // The validator takes its subject by reference, so it is a variable.
    $data = json_decode($json);
    $validator = new Validator();
    $validator->validate($data, BaseConstraint::arrayToObjectRecursive($this->schema));

    return RequestValidator::formatErrors($validator);
  }

}
