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
   * Constructs the editorial context.
   *
   * @param string|null $toneId
   *   The selected tone term id, or NULL when no tone is selected.
   * @param string|null $toneLabel
   *   The tone label at resolution time.
   * @param string|null $tonePrompt
   *   The resolved tone prompt text injected into every sub-agent.
   * @param string|null $templateId
   *   The resolved drafting template id, or NULL without a template.
   * @param string|null $templateLabel
   *   The template label at resolution time.
   * @param array $documents
   *   Document descriptors, each {id, title, category, summary, meta} with
   *   category either "context" or "publishable". Always empty until the
   *   documents backend lands.
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
   *   or NULL) and documents (the descriptor list, possibly empty).
   */
  public function toSnapshot(): array {
    return [
      'tone' => $this->toneId !== NULL && $this->toneId !== ''
        ? ['id' => $this->toneId, 'label' => (string) $this->toneLabel, 'prompt' => (string) $this->tonePrompt]
        : NULL,
      'template' => $this->templateId !== NULL && $this->templateId !== ''
        ? ['id' => $this->templateId, 'label' => (string) $this->templateLabel]
        : NULL,
      'documents' => $this->documents,
    ];
  }

}
