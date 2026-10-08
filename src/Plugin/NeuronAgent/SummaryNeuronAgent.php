<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The agent that summarises an attached document for the editor.
 */
#[NeuronAgent(
  id: 'document_summary',
  label: new TranslatableMarkup('Document summary'),
  description: new TranslatableMarkup('Writes a short summary of an extracted document.'),
  operation_type: 'chat',
)]
final class SummaryNeuronAgent extends NeuronAgentPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return 'You summarise briefing documents for editors. '
      . 'Write a brief summary of the document in English, three to five sentences: '
      . 'what it is and its key points. Return only the summary.';
  }

}
