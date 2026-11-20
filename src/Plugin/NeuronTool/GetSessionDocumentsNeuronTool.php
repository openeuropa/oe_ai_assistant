<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;

/**
 * Returns every document attached to this session as background.
 */
#[NeuronTool(
  id: 'context_get_session_documents',
  description: 'Returns every document attached to this session as background: its ID, title,'
  . ' filename, and either its summary or why its content is not available yet (still'
  . ' processing, or processing failed). Never the full text - call context_read_document'
  . ' for that.',
  label: new TranslatableMarkup('Get session documents'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetSessionDocumentsNeuronTool extends NeuronToolPluginBase {

  /**
   * Returns the attached documents as JSON.
   */
  public function __invoke(): string {
    $documents = array_map(
      [$this, 'describeDocument'],
      $this->getContextValue('session')->getDocuments(),
    );

    return (string) json_encode(['documents' => $documents], JSON_INVALID_UTF8_SUBSTITUTE);
  }

  /**
   * Describes one document: its summary, or why it has none yet.
   *
   * @param array $document
   *   The document descriptor the session carries.
   *
   * @return array
   *   The document with 'id', 'title', 'filename', 'summary' and 'note'.
   *   Exactly one of 'summary'/'note' is non-null.
   */
  private function describeDocument(array $document): array {
    $id = (string) ($document['id'] ?? '');
    $title = (string) ($document['title'] ?? ($id !== '' ? $id : 'Document'));
    $filename = trim((string) ($document['filename'] ?? ''));
    $summary = trim((string) ($document['summary'] ?? ''));

    $note = NULL;
    if ($summary === '') {
      $note = ($document['status'] ?? '') === DocumentExtractionProcessorInterface::STATE_ERROR
        ? 'Processing failed; its content is not available.'
        : 'Not processed yet; its content is not available.';
    }

    return [
      'id' => $id,
      'title' => $title,
      'filename' => $filename,
      'summary' => $summary !== '' ? $summary : NULL,
      'note' => $note,
    ];
  }

}
