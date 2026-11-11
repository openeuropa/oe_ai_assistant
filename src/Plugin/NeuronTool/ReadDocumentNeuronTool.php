<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Plugin\NeuronAgent\EditorialSessionAgentBase;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
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
  id: 'context_read_document',
  description: 'Returns the full text of one document attached to this session.'
  . ' Call it when a summary is not enough to answer, naming the'
  . ' document by the ID context_get_editorial_context reported.',
  label: new TranslatableMarkup('Read document'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class ReadDocumentNeuronTool extends NeuronToolPluginBase {

  /**
   * {@inheritdoc}
   *
   * No enum here: building it would call getDocuments() on the session
   * context while the tool itself is still being constructed, which breaks
   * any caller that builds the tool generically. An unknown id is instead
   * reported as a readable error from __invoke(), which only touches the
   * context once the tool actually runs.
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'document',
        PropertyType::STRING,
        'The ID of the document to read.',
        TRUE,
      ),
    ];
  }

  /**
   * Returns the text of the document, or why it is not available.
   */
  public function __invoke(string $document): string {
    $session = $this->getContextValue('session');
    $descriptor = $session->getDocument($document);
    if ($descriptor === NULL) {
      $ids = array_column($session->getDocuments(), 'id');

      return json_encode([
        'error' => sprintf(
          'No document %s is attached to this session. The documents are: %s.',
          $document,
          $ids === [] ? 'none' : implode(', ', $ids),
        ),
      ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    $extract = trim((string) ($descriptor['extract'] ?? ''));
    [$extract, $truncated] = EditorialSessionAgentBase::truncateDocumentExtract($extract);
    $summary = trim((string) ($descriptor['summary'] ?? ''));
    $status = (string) ($descriptor['status'] ?? '');

    // Nothing to read yet: tell the model why, so it does not guess at the
    // content or claim the document is empty.
    $note = NULL;
    if ($extract === '' && $summary === '') {
      $note = $status === DocumentExtractionProcessorInterface::STATE_ERROR
        ? 'Processing failed; its content is not available. Tell the editor to retry it or remove it.'
        : 'Not processed yet; its content is not available. Tell the editor to wait a moment.';
    }

    return json_encode([
      'id' => $document,
      'title' => $descriptor['title'] ?? '',
      'status' => $status,
      'text' => $extract !== '' ? $extract : NULL,
      'truncated' => $truncated,
      'summary' => $summary,
      'note' => $note,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
  }

}
