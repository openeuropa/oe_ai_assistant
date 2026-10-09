<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Tool returning the extracted text of one attached document.
 *
 * The editorial context tool lists the documents with their summaries;
 * this reads the one the answer actually needs, so a long briefing is only
 * spelled out when it is used.
 */
final class ReadDocumentTool extends Tool {

  public const NAME = 'read_document';

  /**
   * ReadDocumentTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext $context
   *   The editorial context holding the documents of this turn.
   */
  public function __construct(
    private readonly EditorialContext $context,
  ) {
    parent::__construct(
      self::NAME,
      'Returns the full text of one document attached to this session.'
      . ' Call it when a summary is not enough to answer, naming the'
      . ' document by the id get_editorial_context reported.',
    );
  }

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
        array_column($this->context->contextDocuments, 'id'),
      ),
    ];
  }

  /**
   * Returns the text of the document, or why it is not available.
   */
  public function __invoke(string $document): string {
    foreach ($this->context->contextDocuments as $descriptor) {
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
        implode(', ', array_column($this->context->contextDocuments, 'id')) ?: 'none',
      ),
    ]);
  }

}
