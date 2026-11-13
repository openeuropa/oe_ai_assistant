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
   * Characters of one context document extract injected into a prompt.
   */
  public const int MAX_DOCUMENT_CHARS = 20000;

  /**
   * Characters of extracted text injected over all context documents.
   *
   * A context document whose text does not fit in the remaining budget
   * contributes its summary instead.
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
   * @param array $contextDocuments
   *   Context document descriptors, each {id, title, category, status,
   *   filename, summary, meta, extract}. Only documents of the "context"
   *   category are injected as background reading. The extract is the full
   *   text when the pipeline produced one, NULL otherwise.
   * @param array $publishableAssets
   *   Publishable asset descriptors, each {id, title, category, status,
   *   filename, summary, meta, extract}, the same shape the asset repository
   *   serialises. Unlike context documents these are references to material
   *   the editor wants EMBEDDED in the generated content, not background
   *   reading; they are injected so the model knows which assets exist and
   *   can decide where each is used, never read verbatim. The summary holds
   *   the LLM description used to place the asset (empty for an image whose
   *   extraction has not produced one).
   */
  public function __construct(
    public readonly ?string $toneId,
    public readonly ?string $toneLabel,
    public readonly ?string $tonePrompt,
    public readonly ?string $templateId,
    public readonly ?string $templateLabel,
    public readonly array $contextDocuments = [],
    public readonly array $publishableAssets = [],
  ) {}

  /**
   * Flattens the context into the provenance snapshot stored on a draft.
   *
   * @return array
   *   An array with tone ({id, label, prompt} or NULL), template ({id, label}
   *   or NULL) and documents (the context document descriptors without the
   *   file name and the extracted text, possibly empty).
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
        unset($document['filename'], $document['extract']);
        return $document;
      }, $this->contextDocuments),
    ];
  }

  /**
   * Builds the prompt text injected into content-producing agents.
   *
   * One crafted block gathers every piece of editorial context that must
   * steer generation, so all injection sites share the same wording: the
   * tone guidelines and the context documents.
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
    $contextDocuments = $this->toContextDocumentsPrompt();
    if ($contextDocuments !== '') {
      $blocks[] = $contextDocuments;
    }

    return implode("\n\n", $blocks);
  }

  /**
   * Builds the context documents block of the prompts.
   *
   * Every attached context document appears under its title and file name,
   * so the agents can tell which document the editor refers to: the
   * extracted text when the pipeline produced one, otherwise a note that
   * the content is not available. The router and the sub-agents share this
   * block, so the assistant can ask the editor to wait for pending material
   * or to retry failed material. Text is capped per document and over all
   * documents; a document whose text does not fit in the remaining budget
   * contributes its summary instead, and counts as not available while it
   * has none.
   *
   * @return string
   *   The prompt block, or an empty string without context documents.
   */
  public function toContextDocumentsPrompt(): string {
    if ($this->contextDocuments === []) {
      return '';
    }

    $lines = ['Context documents attached by the editor as background for this draft:'];
    $budget = self::MAX_TOTAL_CHARS;
    $pending = FALSE;
    $failed = FALSE;
    foreach ($this->contextDocuments as $document) {
      $lines[] = '';
      $heading = '### ' . (string) ($document['title'] ?? $document['id'] ?? 'Document');
      $filename = trim((string) ($document['filename'] ?? ''));
      $lines[] = $filename === '' ? $heading : sprintf('%s (file: %s)', $heading, $filename);
      $extract = trim((string) ($document['extract'] ?? ''));
      $truncated = mb_strlen($extract) > self::MAX_DOCUMENT_CHARS;
      if ($truncated) {
        $extract = mb_substr($extract, 0, self::MAX_DOCUMENT_CHARS);
      }
      $summary = trim((string) ($document['summary'] ?? ''));
      if ($extract !== '' && mb_strlen($extract) <= $budget) {
        $budget -= mb_strlen($extract);
        $lines[] = $extract;
        // The marker is ours: it stays out of the budget, which only counts
        // document text.
        if ($truncated) {
          $lines[] = '[truncated]';
        }
      }
      elseif ($summary !== '') {
        $lines[] = 'Summary only: ' . $summary;
      }
      // The document has nothing to contribute: no text yet, or text that
      // does not fit and no summary to stand in for it. Waiting only helps a
      // document still in the pipeline; a failed one stays unavailable until
      // the editor retries it.
      elseif (($document['status'] ?? '') === DocumentExtractionProcessorInterface::STATE_ERROR) {
        $failed = TRUE;
        $lines[] = 'Processing failed; its content is not available.';
      }
      else {
        $pending = TRUE;
        $lines[] = 'Not processed yet; its content is not available.';
      }
    }

    $lines[] = '';
    $lines[] = 'Use the context documents as background only: never reproduce them verbatim and do not mention '
      . 'them unless the editor asks.';
    if ($pending) {
      $lines[] = 'Some documents are not available yet. Tell the editor to wait a moment for the full context, '
        . 'or warn that a draft produced now may miss part of the briefing material.';
    }
    if ($failed) {
      $lines[] = 'Some documents could not be processed. Tell the editor to retry them or to remove them, '
        . 'and warn that a draft produced now ignores their content.';
    }

    return implode("\n", $lines);
  }

  /**
   * Builds the publishable assets block of the system prompt.
   *
   * Every asset the editor attached for use in the output is listed as a
   * reference: its id, file name, type and the description produced by the
   * extraction pipeline. Unlike the context documents block, this is not
   * background reading: the assets are material to embed or place in the
   * generated content, and the model addresses one by its id when it decides
   * where to use it. No file bytes or extracts travel here; references are
   * short, so there is no character budget. An asset without a description
   * (an image whose extraction produced none) is still listed so the model
   * can place it by file name.
   *
   * @return string
   *   The prompt block, or an empty string without publishable assets.
   */
  public function toPublishableAssetsPrompt(): string {
    if ($this->publishableAssets === []) {
      return '';
    }

    $lines = ['Publishable assets attached by the editor to use in this draft:'];
    foreach ($this->publishableAssets as $asset) {
      $lines[] = '';
      $id = (string) ($asset['id'] ?? '');
      $filename = trim((string) ($asset['filename'] ?? ''));
      $type = trim((string) ($asset['meta']['type'] ?? ''));
      // The heading carries the id the model must use to reference the asset,
      // plus the file name and type so it can tell assets apart.
      $heading = sprintf('### Asset %s', $id !== '' ? $id : ($filename ?: 'unknown'));
      if ($filename !== '') {
        $heading .= sprintf(' (file: %s', $filename);
        $heading .= $type !== '' ? sprintf(', type: %s)', $type) : ')';
      }
      elseif ($type !== '') {
        $heading .= sprintf(' (type: %s)', $type);
      }
      $lines[] = $heading;
      $summary = trim((string) ($asset['summary'] ?? ''));
      $lines[] = $summary !== ''
        ? 'Description: ' . $summary
        : 'No description available yet.';
    }

    $lines[] = '';
    $lines[] = 'These assets are for embedding or placement in the generated content, not background reading. '
      . 'When you use one, reference it by its asset id; never invent an id or use an asset that is not listed here.';

    return implode("\n", $lines);
  }

}
