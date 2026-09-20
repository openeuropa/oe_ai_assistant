<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Agent that produces the field values of one schema group.
 */
final class ContentDrafterAgent extends Agent {

  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    private readonly string $systemPrompt,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function provider(): AIProviderInterface {
    return $this->aiProvider;
  }

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return $this->systemPrompt;
  }

  /**
   * Runs one inference and returns the JSON object matching the schema.
   *
   * @param \NeuronAI\Chat\Messages\Message|\NeuronAI\Chat\Messages\Message[] $messages
   *   The task messages.
   * @param string $name
   *   The schema name sent to the provider.
   * @param array $schema
   *   The JSON schema the response must match.
   *
   * @return array
   *   The decoded response.
   *
   * @throws \Throwable
   *   When the provider call fails or the response holds no JSON object.
   */
  public function draft(Message|array $messages, string $name, array $schema): array {
    $this->resolveStartEvent()->setMessages(...(is_array($messages) ? $messages : [$messages]));
    $this->compose(new JsonSchemaOutputNode($this->resolveProvider(), $name, $schema));
    $state = $this->init()->run();
    return $state->get(JsonSchemaOutputNode::OUTPUT_KEY) ?? [];
  }

}
