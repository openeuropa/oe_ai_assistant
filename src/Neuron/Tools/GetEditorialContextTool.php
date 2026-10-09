<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Neuron\Tools;

use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use NeuronAI\Tools\Tool;

/**
 * Tool describing what the editor set up for this session.
 *
 * The tone, the template and the attached documents, the documents by title
 * and summary rather than their text, so the answer stays short. The full
 * text of one document comes from read_document.
 */
final class GetEditorialContextTool extends Tool {

  public const NAME = 'get_editorial_context';

  /**
   * GetEditorialContextTool constructor.
   *
   * @param \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext $context
   *   The editorial context of this turn.
   */
  public function __construct(
    private readonly EditorialContext $context,
  ) {
    parent::__construct(
      self::NAME,
      'Returns what the editor set up for this session: the tone, the'
      . ' template and the documents attached as background, each with its'
      . ' title, processing state and summary. Call it to answer what the'
      . ' session is about, what material is attached or what it covers.',
    );
  }

  /**
   * Returns the tone, the template and the attached documents as JSON.
   */
  public function __invoke(): string {
    return json_encode([
      'tone' => $this->context->toneLabel === NULL ? NULL : [
        'label' => $this->context->toneLabel,
        'guidelines' => $this->context->tonePrompt,
      ],
      'template' => $this->context->templateLabel === NULL ? NULL : [
        'label' => $this->context->templateLabel,
      ],
      'documents' => array_map(
        static fn (array $document): array => [
          'id' => $document['id'] ?? '',
          'title' => $document['title'] ?? '',
          'filename' => $document['filename'] ?? '',
          'status' => $document['status'] ?? '',
          'summary' => $document['summary'] ?? '',
        ],
        $this->context->contextDocuments,
      ),
    ]);
  }

}
