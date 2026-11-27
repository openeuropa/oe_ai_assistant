<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Plugin\NeuronAgent\GetSessionActivityNeuronAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Answers a question about what happened in this session.
 *
 * The editorial agent binds the session as tool context, so the model
 * cannot ask about another session. GetSessionActivityNeuronAgent reads the
 * activity record, and we return only its answer, so the editorial thread
 * never stores the record itself.
 */
#[NeuronTool(
  id: 'context_get_session_activity',
  description: 'Answers a question about what changed in this session, when, and'
  . ' in which order. The record covers the tones and templates the editor'
  . ' picked, the documents attached or removed, the drafts generated or'
  . ' failed, and the drafts saved to the CMS, and it says when each one'
  . ' happened and who did it. Call it with the editor\'s question for the'
  . ' history of the session. For what the session holds now, and what else'
  . ' the editor can pick, call context_get_editorial_context instead.',
  label: new TranslatableMarkup('Get session activity'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetSessionActivityNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronAgentManagerInterface $agents,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * GetSessionActivityNeuronAgent never sees the editorial conversation. A
   * follow-up such as "how many times?" means nothing on its own, so the
   * question has to carry what it refers to.
   */
  protected function properties(): array {
    return [
      ToolProperty::make(
        'question',
        PropertyType::STRING,
        'A question that stands on its own, with every "it", "that" and'
        . ' "how many" resolved against this conversation. Keep the words the'
        . ' editor used, and name what they refer to.',
        TRUE,
      ),
    ];
  }

  /**
   * Answers the question from the activity record of the session.
   */
  public function __invoke(string $question): string {
    $agent = $this->agents->createAgent(GetSessionActivityNeuronAgent::PLUGIN_ID, [
      'session' => $this->getContextValue('session'),
    ]);

    $answer = $agent->chat(new UserMessage($question))->getMessage()?->getContent() ?? '';

    // A model writes the answer, so it can hold bytes that are not valid
    // UTF-8. Encoding throws on those, and the editorial agent's tool error
    // handler answers the model with a failure instead.
    return json_encode(['answer' => $answer], JSON_THROW_ON_ERROR);
  }

}
