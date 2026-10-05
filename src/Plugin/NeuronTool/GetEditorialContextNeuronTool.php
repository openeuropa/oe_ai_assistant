<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingTurn;

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
)]
final class GetEditorialContextNeuronTool extends NeuronToolPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DraftingTurn $turn,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Returns the tone, the template and the attached documents as JSON.
   */
  public function __invoke(): string {
    $context = $this->turn->editorialContext();

    return json_encode([
      'tone' => $context->toneLabel === NULL ? NULL : [
        'label' => $context->toneLabel,
        'guidelines' => $context->tonePrompt,
      ],
      'template' => $context->templateLabel === NULL ? NULL : [
        'label' => $context->templateLabel,
      ],
      'documents' => array_map(
        static fn (array $document): array => [
          'id' => $document['id'] ?? '',
          'title' => $document['title'] ?? '',
          'filename' => $document['filename'] ?? '',
          'status' => $document['status'] ?? '',
          'summary' => $document['summary'] ?? '',
        ],
        $context->contextDocuments,
      ),
    ]);
  }

}
