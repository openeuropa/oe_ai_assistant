<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Plugin\NeuronAgent\GetEditorialContextNeuronAgent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Answers one question about what the editor set up for this session.
 *
 * We hand the question to GetEditorialContextNeuronAgent and return its
 * answer, not the session data itself. That agent is built fresh for every
 * call, so it answers from the tone, the template and the documents as they
 * stand now.
 */
#[NeuronTool(
  id: 'context_get_editorial_context',
  description: 'Answers one question about how this session stands now: the tone'
  . ' and the template it holds, the options the editor can pick instead, and'
  . ' the documents attached as background. Call it with the question itself,'
  . ' close to the editor\'s own wording, to answer what the session is about,'
  . ' what material is attached or what it covers, or which tones or templates'
  . ' the editor can choose from. For what changed, when, or in which order,'
  . ' call context_get_session_activity instead.',
  label: new TranslatableMarkup('Get editorial context'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetEditorialContextNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronAgentManagerInterface $agentManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'question',
        PropertyType::STRING,
        "The editor's question, close to their own wording, about the "
        . 'session\'s tone, template, or the documents attached as '
        . 'background.',
        TRUE,
      ),
    ];
  }

  /**
   * Answers the question from the session's current state.
   */
  public function __invoke(string $question): string {
    $session = $this->getContextValue('session');

    $agent = $this->agentManager->createAgent(
      GetEditorialContextNeuronAgent::PLUGIN_ID,
      ['session' => $session],
    );

    $message = $agent->chat(new UserMessage($question))->getMessage();
    $answer = trim((string) $message?->getContent());

    if ($answer === '') {
      throw new \RuntimeException('The editorial context agent returned no answer.');
    }

    // Every tool here answers with a JSON object; the tool card on the
    // frontend reads a plain string result as Neuron's own validation
    // failure text, so the answer is wrapped the same way the others are.
    return (string) json_encode(['answer' => $answer], JSON_INVALID_UTF8_SUBSTITUTE);
  }

}
