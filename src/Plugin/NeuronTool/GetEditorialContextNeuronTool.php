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
      // Only the tone's label goes here, not its guidelines text. This
      // tool's result is saved as a thread row, and if the editor changes
      // the tone later, a saved row with the old guidelines would still
      // read as a current instruction. tonePrompt() renders the guidelines
      // fresh, only when GroupDrafterNeuronAgent actually drafts a field.
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
