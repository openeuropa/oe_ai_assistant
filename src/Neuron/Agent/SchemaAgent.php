<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Agent;

use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaRetryNode;
use NeuronAI\Agent\Agent;

/**
 * An agent that answers one JSON schema given at run time.
 *
 * Neuron's own structured output derives the schema from a PHP class, and a
 * drafting schema is composed per bundle and template, so the chat and tool
 * nodes are replaced by a node that sends the schema and validates against it.
 * An answer that does not match routes to the retry node.
 */
final class SchemaAgent extends Agent {

  /**
   * What the answer is named, which routes it in the state.
   */
  private string $schemaName = '';

  /**
   * The schema the answer is validated against.
   */
  private array $schema = [];

  /**
   * Names the schema this agent answers.
   *
   * @param string $name
   *   The name the answer is stored under.
   * @param array $schema
   *   The JSON schema.
   *
   * @return static
   *   The agent.
   */
  public function forSchema(string $name, array $schema): static {
    $this->schemaName = $name;
    $this->schema = $schema;

    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * One run answers one schema and holds no conversation, so there is nothing
   * for a chat node or a tool node to do.
   */
  protected function nodes(): array {
    return [
      ...$this->entryNodes(),
      new SchemaOutputNode($this->schemaName, $this->schema),
      new SchemaRetryNode(),
      ...$this->exitNodes(),
    ];
  }

}
