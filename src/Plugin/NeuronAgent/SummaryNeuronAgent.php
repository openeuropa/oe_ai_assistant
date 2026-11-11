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
   * The base class tags a request with just this plugin's id. Kept at the
   * pair the direct provider call this agent replaced used to send, so a
   * site matching moderation or prompt-logging config on 'oe_ai_assistant'
   * or 'document_summary' keeps screening this input, the one text here an
   * editor fully controls through an uploaded file.
   */
  protected function provider(): AIProviderInterface {
    return $this->providers->create($this->operationType(), ['oe_ai_assistant', 'document_summary']);
  }

}
