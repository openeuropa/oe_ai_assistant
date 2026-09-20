<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\Workflow\Events\StopEvent;

/**
 * Inference node that asks for JSON matching a schema given at runtime.
 *
 * Neuron's own structured output derives the schema from a PHP class. The
 * drafting schemas are composed per bundle and template, so this node
 * passes the schema array straight to the provider and stores the decoded
 * object in the state.
 */
final class JsonSchemaOutputNode extends InferenceNode {

  use ChatHistoryHelper;

  /**
   * The state key holding the decoded output.
   */
  public const OUTPUT_KEY = 'json_schema_output';

  /**
   * JsonSchemaOutputNode constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $provider
   *   The provider to call.
   * @param string $name
   *   The schema name sent to the provider.
   * @param array $schema
   *   The JSON schema the response must match.
   * @param \NeuronAI\StructuredOutput\JsonExtractor $extractor
   *   The extractor that finds the JSON object in the response text.
   */
  public function __construct(
    private readonly AIProviderInterface $provider,
    private readonly string $name,
    private readonly array $schema,
    private readonly JsonExtractor $extractor = new JsonExtractor(),
  ) {}

  /**
   * {@inheritdoc}
   *
   * @throws \NeuronAI\Exceptions\AgentException
   *   When the response holds no JSON object.
   */
  public function __invoke(AIInferenceEvent $event, AgentState $state): StopEvent {
    $inbound = $event->getMessages();
    $messages = $this->pendingConversation($state, $inbound);
    $last = end($messages);

    $this->emit('inference-start', new InferenceStart($last));
    $response = $this->provider
      ->systemPrompt($event->instructions)
      ->setTools([])
      ->structured($messages, $this->name, $this->schema);
    $this->emit('inference-stop', new InferenceStop($last, $response));

    $this->addToChatHistory($state, [...$inbound, $response]);

    $json = $this->extractor->getJson($response->getContent() ?? '');
    if ($json === NULL) {
      throw new AgentException(sprintf('The "%s" response is not a valid JSON object.', $this->name));
    }
    $state->set(self::OUTPUT_KEY, json_decode($json, TRUE));

    return new StopEvent();
  }

}
