<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Neuron\Agent\SchemaAgent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\UserMessage;

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
    'task' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Task'),
    ),
  ],
)]
final class FieldGroupNeuronAgent extends EditorialSessionAgentBase {

  /**
   * How many corrected answers to ask for before giving up.
   */
  private const MAX_SCHEMA_RETRIES = 5;

  /**
   * What marks a thread segment as one run of this drafter.
   */
  private const RUN_PREFIX = 'run_';

  /**
   * What marks a thread segment as the group a run answered for.
   */
  private const GROUP_PREFIX = 'group_';

  /**
   * {@inheritdoc}
   *
   * Built here rather than by the base class, because the answer is validated
   * against a schema composed at run time, which takes the place of the chat
   * and tool nodes an agent runs by default. The task is the whole of what the
   * model is asked, so the run starts from it.
   */
  public function getNeuron(): AgentInterface {
    $agent = SchemaAgent::make($this->runId($this->threadKey()))
      ->forSchema($this->group(), (array) $this->getContextValue('schema'));

    $agent->setAiProvider($this->providers->create($this->operationType(), [$this->tag(), $this->group()]));
    $agent->setInstructions($this->instructions());
    $agent->setStartEvent(new AgentStartEvent(
      [new UserMessage((string) $this->getContextValue('task'))],
      new AgentRunOptions(
        outputClass: $this->group(),
        maxRetries: self::MAX_SCHEMA_RETRIES,
      ),
    ));
    $this->applyMiddleware($agent);

    return $agent;
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
   * One thread per run, so a drafter is asked for one answer and never replays
   * the answer it gave for an earlier draft. The random segment is what keeps
   * two runs of one group apart; the session and the group are there so a
   * reader can tell whose run it was.
   */
  protected function threadKey(): string {
    return self::RUN_PREFIX . bin2hex(random_bytes(4)) . '.' . self::GROUP_PREFIX . $this->group();
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
