<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

/**
 * Returns the extracted text of one attached document.
 *
 * The editorial context tool lists the documents with their summaries; this
 * reads the one the answer actually needs, so a long briefing is only
 * spelled out when it is used.
 */
#[NeuronTool(
  id: 'read_document',
  description: 'Returns the full text of one document attached to this session.'
  . ' Call it when a summary is not enough to answer, naming the'
  . ' document by the id get_editorial_context reported.',
  label: new TranslatableMarkup('Read document'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class ReadDocumentNeuronTool extends DraftingToolBase {

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'document',
        PropertyType::STRING,
        'The id of the document to read.',
        TRUE,
        array_column($this->brief->documents($this->session()), 'id'),
      ),
    ];
  }

  /**
   * Returns the text of the document, or why it is not available.
   */
  public function __invoke(string $document): string {
    $documents = $this->brief->documents($this->session());
    foreach ($documents as $descriptor) {
      if ((string) ($descriptor['id'] ?? '') !== $document) {
        continue;
      }
      $extract = trim((string) ($descriptor['extract'] ?? ''));
      return json_encode([
        'id' => $document,
        'title' => $descriptor['title'] ?? '',
        'status' => $descriptor['status'] ?? '',
        'text' => $extract !== '' ? $extract : NULL,
        'summary' => $descriptor['summary'] ?? '',
      ]);
    }

    return json_encode([
      'error' => sprintf(
        'No document %s is attached to this session. The documents are: %s.',
        $document,
        implode(', ', array_column($documents, 'id')) ?: 'none',
      ),
    ]);
  }

}
