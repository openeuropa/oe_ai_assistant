<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Providers\AIProviderInterface;

/**
 * The agent that summarises an attached document for the editor.
 *
 * It has no session: DocumentExtractionProcessor calls it once per document,
 * outside any editorial conversation, so it extends the plain agent base
 * rather than EditorialSessionAgentBase.
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

  /**
   * {@inheritdoc}
   *
   * The base class tags a request with just this plugin's ID. We send the
   * same two-tag pair ('oe_ai_assistant', 'document_summary') that
   * DocumentExtractionProcessor's old direct provider call used to send,
   * so a site's moderation or prompt-logging config keyed on either tag
   * still screens this input: the one text in this flow an editor fully
   * controls, through an uploaded file.
   */
  protected function provider(): AIProviderInterface {
    return $this->providers->create($this->operationType(), ['oe_ai_assistant', 'document_summary']);
  }

}
