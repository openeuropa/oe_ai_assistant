<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Describes what the editor set up for this session.
 *
 * The tone, the template and the attached documents, the documents by title
 * and summary rather than their text, so the answer stays short. The full
 * text of one document comes from context_read_document.
 */
#[NeuronTool(
  id: 'context_get_editorial_context',
  description: 'Returns what the editor set up for this session: the tone, the'
  . ' template and the documents attached as background, each with its'
  . ' title, processing state and summary. Call it to answer what the'
  . ' session is about, what material is attached or what it covers.',
  label: new TranslatableMarkup('Get editorial context'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetEditorialContextNeuronTool extends NeuronToolPluginBase {

  /**
   * Returns the tone, the template and the attached documents as JSON.
   */
  public function __invoke(): string {
    $session = $this->getContextValue('session');
    $tone = $session->getTone();
    $template = $session->getTemplate();

    return json_encode([
      // The guidelines text itself is left to the drafters
      // (EditorialSessionAgentBase::tonePrompt()): this agent writes no
      // field of the draft, and a tool result lands in a persisted thread
      // row, so it would outlive a later tone change and read as a
      // standing instruction rather than the fact that it is.
      'tone' => $tone === NULL ? NULL : [
        'label' => $tone['label'],
      ],
      'template' => $template === NULL ? NULL : [
        'label' => $template['label'],
      ],
      'documents' => array_map(
        static fn (array $document): array => [
          'id' => $document['id'] ?? '',
          'title' => $document['title'] ?? '',
          'filename' => $document['filename'] ?? '',
          'status' => $document['status'] ?? '',
          'summary' => $document['summary'] ?? '',
        ],
        $session->getDocuments(),
      ),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
  }

}
