<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Describes what the editor set up for this session.
 *
 * The tone, the template and the attached documents, the documents by title
 * and summary rather than their text, so the answer stays short. The full
 * text of one document comes from read_document.
 */
#[NeuronTool(
  id: 'get_editorial_context',
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
final class GetEditorialContextNeuronTool extends DraftingToolBase {

  /**
   * Returns the tone, the template and the attached documents as JSON.
   */
  public function __invoke(): string {
    $session = $this->session();
    $tone = $this->brief->tone($session);
    $template = $this->brief->template($session);

    return json_encode([
      'tone' => $tone === NULL ? NULL : [
        'label' => $tone['label'],
        'guidelines' => $tone['prompt'],
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
        $this->brief->documents($session),
      ),
    ]);
  }

}
