<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\DrupalAiProvider;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Providers\AIProviderInterface;

/**
 * Writes the field values of one field group against its JSON schema.
 *
 * One run answers one schema and holds no conversation: the task carries
 * everything the model needs.
 */
#[NeuronAgent(
  id: 'field_group',
  label: new TranslatableMarkup('Field group drafter'),
  description: new TranslatableMarkup('Writes the values of one field group against its JSON schema.'),
  operation_type: 'chat_with_structured_response',
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'group' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Field group'),
    ),
    'schema' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup('Group schema'),
    ),
    'run' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Drafting run'),
    ),
  ],
)]
final class FieldGroupNeuronAgent extends EditorialSessionAgentBase {

  /**
   * {@inheritdoc}
   *
   * The drafter answers one group, so its calls carry that group's JSON
   * schema and the model returns one JSON object of that shape. The tool that
   * started the run checks the answer against the same schema.
   */
  protected function provider(): AIProviderInterface {
    $provider = $this->providers->create($this->operationType(), [$this->tag(), $this->group()]);
    // The site's provider is the only implementation, and the schema is its
    // own addition rather than part of Neuron's interface.
    assert($provider instanceof DrupalAiProvider);

    return $provider->withStructuredSchema($this->group(), (array) $this->getContextValue('schema'));
  }

  /**
   * {@inheritdoc}
   *
   * The tone steers what the drafter writes, and the documents are the
   * material it writes from, so a drafter is told both.
   */
  protected function instructions(): string {
    $instructions = <<<'PROMPT'
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

    $blocks = array_filter([$this->tonePrompt(), $this->documentsPrompt()]);

    return $blocks === []
      ? $instructions
      : $instructions . "\n\n" . implode("\n\n", $blocks) . "\n";
  }

  /**
   * {@inheritdoc}
   *
   * One thread per group per run, so a drafter answers once and never
   * replays the answer it gave for an earlier draft. The run names the
   * draft it produces, so a reader of the stored conversation can tell which
   * draft a run belongs to.
   */
  protected function threadKey(): string {
    return parent::threadKey()
      . '.' . (string) $this->getContextValue('run')
      . '.group_' . $this->group();
  }

  /**
   * The group this run answers for.
   *
   * @return string
   *   The group id, which names the run and routes its structured output.
   */
  private function group(): string {
    return (string) $this->getContextValue('group');
  }

}
