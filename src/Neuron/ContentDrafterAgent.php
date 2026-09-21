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

  /**
   * The instructions every run starts from.
   */
  public const INSTRUCTIONS = <<<'PROMPT'
    You are a content generator. You will receive a JSON schema and
    instructions describing what content to produce. Generate a JSON
    object that conforms exactly to the given schema. Return ONLY
    valid JSON with no markdown fencing, no explanation, no commentary.

    Every field value is an array of items, each item an object with
    the property keys the schema lists, for example
    "field_teaser": [{"value": "Short teaser"}]. Use only the property
    names the schema defines, exactly as written, and match its
    array, object and property shape.

    For formatted text fields, produce clean HTML.
    Match the language and tone described in the instructions.
    PROMPT;

  /**
   * ContentDrafterAgent constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $aiProvider
   *   The provider to call.
   * @param string $contextPrompt
   *   Editorial context appended to the instructions, or empty.
   */
  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    private readonly string $contextPrompt,
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
    return $this->contextPrompt === ''
      ? self::INSTRUCTIONS
      : self::INSTRUCTIONS . "\n\n" . $this->contextPrompt . "\n";
  }

  /**
   * Runs one inference and returns the JSON object matching the schema.
   *
   * @param \NeuronAI\Chat\Messages\Message $message
   *   The task message.
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
  public function draft(Message $message, string $name, array $schema): array {
    $this->resolveStartEvent()->setMessages($message);
    $this->compose(new JsonSchemaOutputNode($this->resolveProvider(), $name, $schema));
    $state = $this->init()->run();
    return $state->get(JsonSchemaOutputNode::OUTPUT_KEY) ?? [];
  }

}
