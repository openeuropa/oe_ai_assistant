<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool\Research;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Lets the researcher put one question to the editor.
 *
 * The tool stores nothing: whoever runs the researcher reads the question off
 * the tool call.
 */
#[NeuronTool(
  id: 'research_ask_editor',
  description: 'Asks the editor one question when the request is ambiguous or needs a choice'
  . ' only the editor can make. Call it at most once, then end your turn.',
  label: new TranslatableMarkup('Ask the editor'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class AskEditorNeuronTool extends NeuronToolPluginBase {

  /**
   * {@inheritdoc}
   *
   * Neuron counts the runs of a tool per turn, so one run is all it gets.
   */
  public function getNeuron(): Tool {
    return parent::getNeuron()->setMaxRuns(1);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      ToolProperty::make('question', PropertyType::STRING, 'The question for the editor, complete and self-contained.', TRUE),
    ];
  }

  /**
   * Acknowledges the question.
   */
  public function __invoke(string $question): string {
    return 'Your question has been recorded and will be passed to the editor.'
      . ' End your turn now with one short sentence saying what you need from the editor.';
  }

}
