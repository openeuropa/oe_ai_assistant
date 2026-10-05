<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use Drupal\oe_ai_assistant\Neuron\Agent\Events\SchemaViolationEvent;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Agent\Observability\Validating;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\StructuredOutput\JsonExtractor;

/**
 * Structured output node for a JSON schema given at runtime.
 *
 * Neuron's own structured output derives the schema from a PHP class and
 * validates the deserialized object against its attributes, never the JSON
 * against the schema. The drafting schemas are composed per bundle and
 * template, so this node passes the schema array to the provider, quotes it
 * in the instructions and validates the answer against it. The decoded
 * object ends up in the state; an answer that does not match routes to the
 * retry node instead.
 */
final class SchemaOutputNode extends InferenceNode {

  /**
   * The state key holding the decoded output.
   *
   * Neuron's own key, so structured() hands the values back as it does for
   * an output class.
   */
  public const OUTPUT_KEY = 'structured_output';

  /**
   * The schema as sent to the provider and validated against.
   */
  private readonly array $schema;

  /**
   * The same schema as the object tree the validator reads.
   */
  private readonly object $schemaObject;

  public function __construct(
    private readonly string $name,
    array $schema,
    private readonly JsonExtractor $extractor = new JsonExtractor(),
  ) {
    $this->schema = $schema + [
      'required' => array_keys($schema['properties'] ?? []),
      'additionalProperties' => FALSE,
    ];
    $this->schemaObject = BaseConstraint::arrayToObjectRecursive($this->schema);
  }

  /**
   * Asks the model for the group, validates it and stores it.
   */
  public function __invoke(StructuredInferenceEvent $event, AgentState $state, AgentResources $resources): AgentOutputEvent|SchemaViolationEvent {
    $instructions = $state->request->instructions->getContent()
      . "\n\nRespond with one JSON object that matches this JSON schema exactly."
      . " Use the property names as written in the schema and no others.\n"
      . json_encode($this->schema, JSON_UNESCAPED_SLASHES);

    // The inbound messages reach the history only once the call succeeds,
    // so a failed call leaves no dangling turn to break role alternation.
    $inbound = $state->request->messages;
    $messages = $this->pendingConversation($resources->history, $inbound);
    $last = clone end($messages);

    $this->emit(new InferenceStart($last));
    $attempt = (int) $state->get('schema_retries', 0);
    $response = $this->memoize(
      "inference.{$attempt}",
      fn (): ProviderResponse => $resources->provider
        ->systemPrompt($instructions)
        ->setTools([])
        ->structured($messages, $this->name, $this->schema),
    );
    $message = $response->message();
    $this->emit(new InferenceStop($last, $response));

    $this->addToChatHistory($resources->history, $state, [...$inbound, $message], "history.{$attempt}");
    $state->setResponse($response);

    $json = $this->extractor->getJson($message->getContent() ?? '');
    $this->emit(new Validating($this->name, (string) $json));
    $violations = $json === NULL ? ['The answer holds no JSON object.'] : $this->violations($json);
    $this->emit(new Validated($this->name, (string) $json, $violations));

    if ($violations === []) {
      $state->set(self::OUTPUT_KEY, json_decode($json, TRUE));
      return new AgentOutputEvent();
    }

    return new SchemaViolationEvent($this->name, $violations);
  }

  /**
   * Validates a JSON document against the schema.
   *
   * @return string[]
   *   One line per violation, empty when the document matches.
   */
  private function violations(string $json): array {
    $data = json_decode($json);
    $validator = new Validator();
    $validator->validate($data, $this->schemaObject);
    return RequestValidator::formatErrors($validator);
  }

}
