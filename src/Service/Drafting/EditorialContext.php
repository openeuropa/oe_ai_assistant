<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Immutable editorial context for one drafting request.
 *
 * Resolved once per chat request from the editorial session, passed to the
 * orchestrator for sub-agent prompt injection, and flattened into the
 * provenance snapshot stored on every draft result. Ids travel with the
 * labels resolved at request time so the snapshot preserves what the editor
 * saw even if a term or template is renamed later. The tone prompt string is
 * resolved by the AiEditorialContext service, which stays the single source
 * of tone wording; the orchestrator never resolves tones itself.
 */
final class EditorialContext {

  /**
   * Characters of one document extract injected into a prompt.
   */
  public const int MAX_DOCUMENT_CHARS = 20000;

  /**
   * Characters of extracted text injected into a prompt over all documents.
   *
   * Beyond this budget the remaining documents contribute their summary.
   */
  public const int MAX_TOTAL_CHARS = 60000;

  /**
   * Constructs the editorial context.
   *
   * @param string|null $toneId
   *   The selected tone term id, or NULL when no tone is selected.
   * @param string|null $toneLabel
   *   The tone label at resolution time.
   * @param string|null $tonePrompt
   *   The raw tone guideline text injected into content-producing agents.
   * @param string|null $templateId
   *   The resolved drafting template id, or NULL without a template.
   * @param string|null $templateLabel
   *   The template label at resolution time.
   * @param array $documents
   *   Document descriptors, each {id, title, category, status, summary, meta,
   *   extract} with category either "context" or "publishable". The extract
   *   is the full text when the pipeline produced one, NULL otherwise; it
   *   feeds the prompts and never the snapshot.
   */
  public function __construct(
    public readonly ?string $toneId,
    public readonly ?string $toneLabel,
    public readonly ?string $tonePrompt,
    public readonly ?string $templateId,
    public readonly ?string $templateLabel,
    public readonly array $documents = [],
  ) {}

  /**
   * Flattens the context into the provenance snapshot stored on a draft.
   *
   * @return array
   *   An array with tone ({id, label, prompt} or NULL), template ({id, label}
   *   or NULL) and documents (the descriptor list without the extracted
   *   text, possibly empty).
   */
  public function toSnapshot(): array {
    return [
      'tone' => $this->toneId !== NULL && $this->toneId !== ''
        ? ['id' => $this->toneId, 'label' => (string) $this->toneLabel, 'prompt' => (string) $this->tonePrompt]
        : NULL,
      'template' => $this->templateId !== NULL && $this->templateId !== ''
        ? ['id' => $this->templateId, 'label' => (string) $this->templateLabel]
        : NULL,
      'documents' => array_map(static function (array $document): array {
        unset($document['extract']);
        return $document;
      }, $this->documents),
    ];
  }

  /**
   * Builds the prompt text injected into content-producing agents.
   *
   * One crafted block gathers every piece of editorial context that must
   * steer generation, so all injection sites share the same wording: the
   * tone guidelines and the reference documents.
   *
   * @return string
   *   The prompt block, or an empty string when there is no context.
   */
  public function toPrompt(): string {
    $blocks = [];
    if ($this->tonePrompt !== NULL && $this->tonePrompt !== '') {
      $blocks[] = implode("\n", [
        'Editorial context selected by the editor for this draft:',
        sprintf('- Tone: %s', (string) $this->toneLabel),
        sprintf('- Tone guidelines: %s', $this->tonePrompt),
        '',
        'Follow the tone guidelines in every piece of text you generate.',
      ]);
    }
    $documents = $this->toDocumentsPrompt();
    if ($documents !== '') {
      $blocks[] = $documents;
    }

    return implode("\n\n", $blocks);
  }

  /**
   * Builds the reference documents block of the prompts.
   *
   * Every attached document appears under its title: the extracted text
   * when the pipeline produced one, otherwise a note that the content is
   * not available yet. The router and the sub-agents share this block, so
   * the assistant can warn the editor about pending material. Text is
   * capped per document and over all documents; past the total budget the
   * remaining documents contribute their summary instead.
   *
   * @return string
   *   The prompt block, or an empty string without documents.
   */
  public function toDocumentsPrompt(): string {
    if ($this->documents === []) {
      return '';
    }

    $lines = ['Reference documents attached by the editor as background for this draft:'];
    $budget = self::MAX_TOTAL_CHARS;
    $pending = FALSE;
    foreach ($this->documents as $document) {
      $lines[] = '';
      $lines[] = '### ' . (string) ($document['title'] ?? $document['id'] ?? 'Document');
      $extract = trim((string) ($document['extract'] ?? ''));
      if ($extract === '') {
        $pending = TRUE;
        $lines[] = ($document['status'] ?? '') === 'error'
          ? 'Processing failed; its content is not available.'
          : 'Not processed yet; its content is not available.';
        continue;
      }
      if ($budget <= 0) {
        $lines[] = 'Summary only: ' . trim((string) ($document['summary'] ?? ''));
        continue;
      }
      if (mb_strlen($extract) > self::MAX_DOCUMENT_CHARS) {
        $extract = mb_substr($extract, 0, self::MAX_DOCUMENT_CHARS) . "\n[truncated]";
      }
      $budget -= mb_strlen($extract);
      $lines[] = $extract;
    }

    $lines[] = '';
    $lines[] = 'Use the reference documents as background only: never reproduce them verbatim and do not mention '
      . 'them unless the editor asks.';
    if ($pending) {
      $lines[] = 'Some documents are not available yet. Tell the editor to wait a moment for the full context, '
        . 'or warn that a draft produced now may miss part of the briefing material.';
    }

    return implode("\n", $lines);
  }

}
