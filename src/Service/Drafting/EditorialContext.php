<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

/**
 * Immutable editorial context for one drafting request.
 *
 * Resolved once per chat request from the editorial session, passed to the
 * content-producing agents for prompt injection, and flattened into the
 * provenance snapshot stored on every draft result. Ids travel with the
 * labels resolved at request time so the snapshot preserves what the editor
 * saw even if a term or template is renamed later. The tone prompt string is
 * resolved by the AiEditorialContext service, which stays the single source
 * of tone wording.
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
   *   The raw tone guideline text injected into content-producing agents.
   * @param string|null $templateId
   *   The resolved drafting template id, or NULL without a template.
   * @param string|null $templateLabel
   *   The template label at resolution time.
   * @param array $documents
   *   Document descriptors, each {id, title, category, summary, meta} with
   *   category either "context" or "publishable". Always empty until the
   *   documents backend lands.
   * @param array $groups
   *   The schema groups the draft is written against, each with groupId,
   *   label, fieldNames and schemaSlice.
   */
  public function __construct(
    public readonly ?string $toneId,
    public readonly ?string $toneLabel,
    public readonly ?string $tonePrompt,
    public readonly ?string $templateId,
    public readonly ?string $templateLabel,
    public readonly array $documents = [],
    public readonly array $groups = [],
  ) {}

  /**
   * Flattens the context into the provenance snapshot stored on a draft.
   *
   * The schema groups travel with it, so a draft can be revised against
   * the structure it was written with rather than whatever the session
   * points at later.
   *
   * @return array
   *   An array with tone ({id, label, prompt} or NULL), template ({id, label}
   *   or NULL), documents (the descriptor list, possibly empty) and groups.
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
      'groups' => $this->groups,
    ];
  }

  /**
   * Builds the prompt text injected into content-producing agents.
   *
   * One crafted block gathers every piece of editorial context that must
   * steer generation, so all injection sites share the same wording. Only
   * the tone contributes today; document summaries join it when the
   * documents backend lands.
   *
   * @return string
   *   The prompt block, or an empty string when there is no context.
   */
  public function toPrompt(): string {
    $lines = [];
    if ($this->tonePrompt !== NULL && $this->tonePrompt !== '') {
      $lines[] = sprintf('- Tone: %s', (string) $this->toneLabel);
      $lines[] = sprintf('- Tone guidelines: %s', $this->tonePrompt);
    }
    if ($lines === []) {
      return '';
    }
    return implode("\n", [
      'Editorial context selected by the editor for this draft:',
      ...$lines,
      '',
      'Follow the tone guidelines in every piece of text you generate.',
    ]);
  }

}
