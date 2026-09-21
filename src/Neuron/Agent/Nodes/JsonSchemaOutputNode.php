<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent\Nodes;

use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\Validator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\Validated;
use NeuronAI\Observability\Events\Validating;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\Workflow\Events\StopEvent;

/**
 * Inference node that asks for JSON matching a schema given at runtime.
 *
 * Neuron's own structured output derives the schema from a PHP class. The
 * drafting schemas are composed per bundle and template, so this node
 * passes the schema array to the provider, quotes it in the instructions,
 * validates the answer against it and retries with the violations. The
 * decoded object ends up in the state.
 */
final class JsonSchemaOutputNode extends InferenceNode {

  use ChatHistoryHelper;

  /**
   * The state key holding the decoded output.
   */
  public const OUTPUT_KEY = 'json_schema_output';

  /**
   * The schema as sent to the provider and validated against.
   */
  private readonly array $schema;

  /**
   * The same schema as the object tree the validator reads.
   */
  private readonly object $schemaObject;

  /**
   * JsonSchemaOutputNode constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $provider
   *   The provider to call.
   * @param string $name
   *   The schema name sent to the provider.
   * @param array $schema
   *   The JSON schema the response must match. Every top-level property
   *   becomes required and no other property is allowed.
   * @param int $maxRetries
   *   How many corrected answers to ask for before giving up.
   * @param \NeuronAI\StructuredOutput\JsonExtractor $extractor
   *   The extractor that finds the JSON object in the response text.
   */
  public function __construct(
    private readonly AIProviderInterface $provider,
    private readonly string $name,
    array $schema,
    private readonly int $maxRetries = 1,
    private readonly JsonExtractor $extractor = new JsonExtractor(),
  ) {
    $this->schema = $schema + [
      'required' => array_keys($schema['properties'] ?? []),
      'additionalProperties' => FALSE,
    ];
    $this->schemaObject = BaseConstraint::arrayToObjectRecursive($this->schema);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \NeuronAI\Exceptions\AgentException
   *   When no answer matches the schema within the allowed retries.
   */
  public function __invoke(AIInferenceEvent $event, AgentState $state): StopEvent {
    $instructions = $event->instructions
      . "\n\nRespond with one JSON object that matches this JSON schema exactly."
      . " Use the property names as written in the schema and no others.\n"
      . json_encode($this->schema, JSON_UNESCAPED_SLASHES);

    // Inbound and correction messages stay pending until a provider call
    // succeeds, so a failed call leaves no dangling turn in the history.
    $pending = $event->getMessages();
    $violations = [];
    $attempts = $this->maxRetries + 1;

    while (TRUE) {
      if ($violations !== []) {
        $pending[] = new UserMessage(
          "Your previous answer does not match the schema:\n- " . implode("\n- ", $violations)
          . "\n\nAnswer again with one JSON object that matches the schema exactly."
        );
      }
      $messages = $this->pendingConversation($state, $pending);
      $last = end($messages);

      $this->emit('inference-start', new InferenceStart($last));
      $response = $this->provider
        ->systemPrompt($instructions)
        ->setTools([])
        ->structured($messages, $this->name, $this->schema);
      $this->emit('inference-stop', new InferenceStop($last, $response));

      $this->addToChatHistory($state, [...$pending, $response]);
      $pending = [];

      $json = $this->extractor->getJson($response->getContent() ?? '');
      $this->emit('structured-validating', new Validating($this->name, (string) $json));
      $violations = $json === NULL ? ['The answer holds no JSON object.'] : $this->violations($json);
      $this->emit('structured-validated', new Validated($this->name, (string) $json, $violations));

      if ($violations === []) {
        $state->set(self::OUTPUT_KEY, json_decode($json, TRUE));
        return new StopEvent();
      }
      if (--$attempts <= 0) {
        throw new AgentException(sprintf(
          'The "%s" answer does not match its schema: %s',
          $this->name,
          implode('; ', $violations),
        ));
      }
    }
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
    return array_map(
      static fn (array $error): string => trim(($error['property'] !== '' ? $error['property'] . ': ' : '') . $error['message']),
      $validator->getErrors(),
    );
  }

}
