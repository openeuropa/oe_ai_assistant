<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The agent that summarises an attached document for the editor.
 *
 * It takes the document's session as context. EditorialMessageStore reads
 * that context and stamps the session on every row it writes, so the
 * session's history page lists this call and counts its tokens. The agent
 * holds no conversation with the editor and publishes no tools, so it
 * extends the plain agent base rather than EditorialSessionAgentBase.
 */
#[NeuronAgent(
  id: SummaryNeuronAgent::PLUGIN_ID,
  label: new TranslatableMarkup('Document summary'),
  description: new TranslatableMarkup('Writes a short summary of an extracted document.'),
  operation_type: 'chat',
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SummaryNeuronAgent extends NeuronAgentPluginBase {

  /**
   * The id this agent is registered under.
   */
  public const string PLUGIN_ID = 'document_summary';

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    return 'You summarise briefing documents for editors. '
      . 'Write a brief summary of the document in English, three to five sentences: '
      . 'what it is and its key points. Return only the summary.';
  }

}
