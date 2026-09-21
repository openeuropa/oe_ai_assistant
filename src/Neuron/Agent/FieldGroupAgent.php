<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Agent that produces the field values of one field group.
 *
 * One run drafts one group, with no tools: the group's schema is fixed at
 * construction, so structured() answers against it instead of deriving a
 * schema from a PHP class.
 */
final class FieldGroupAgent extends Agent {

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
   * FieldGroupAgent constructor.
   *
   * @param \NeuronAI\Providers\AIProviderInterface $aiProvider
   *   The provider to call.
   * @param string $contextPrompt
   *   Editorial context appended to the instructions, or empty.
   * @param string $name
   *   The schema name sent to the provider.
   * @param array $schema
   *   The JSON schema every answer must match.
   */
  public function __construct(
    private readonly AIProviderInterface $aiProvider,
    private readonly string $contextPrompt,
    private readonly string $name,
    private readonly array $schema,
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
   * {@inheritdoc}
   *
   * The class is ignored: the answer is validated against the schema given
   * at construction and returned decoded.
   *
   * @throws \Throwable
   *   When the provider call fails or no answer matches the schema.
   */
  public function structured(Message|array $messages = [], ?string $class = NULL, int $maxRetries = 1, ?InterruptRequest $interrupt = NULL): mixed {
    $this->resolveStartEvent()->setMessages(...(is_array($messages) ? $messages : [$messages]));
    $this->compose(new SchemaOutputNode($this->resolveProvider(), $this->name, $this->schema, $maxRetries));
    return $this->init($interrupt)->run()->get(SchemaOutputNode::OUTPUT_KEY) ?? [];
  }

}
