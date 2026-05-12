<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds editorial audience and tone context from taxonomy terms.
 */
class AiEditorialContext implements AiEditorialContextInterface {

  /**
   * The vocabulary ID for target audiences.
   */
  protected const string AUDIENCE_VID = 'ai_target_audience';

  /**
   * The vocabulary ID for tones.
   */
  protected const string TONE_VID = 'ai_tone';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAvailableAudiences(): array {
    return $this->loadVocabularyTerms(self::AUDIENCE_VID);
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableTones(): array {
    return $this->loadVocabularyTerms(self::TONE_VID);
  }

  /**
   * {@inheritdoc}
   */
  public function buildSelectionPrompt(): string {
    return implode("\n", [
      'Before drafting, ask the user to select a target audience and writing tone.',
      '',
      'Available target audiences:',
      ...$this->formatPromptOptions($this->getAvailableAudiences()),
      '',
      'Available tones:',
      ...$this->formatPromptOptions($this->getAvailableTones()),
      '',
      "Present these options and wait for the user's selection before proceeding.",
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildSelectedPrompt(string $audienceId, string $toneId): string {
    $audience = $this->loadVocabularyTerm($audienceId, self::AUDIENCE_VID);
    $tone = $this->loadVocabularyTerm($toneId, self::TONE_VID);

    return implode("\n", [
      'The user has selected:',
      sprintf('- Target audience: %s', $audience->label()),
      sprintf('- Tone: %s', $tone->label()),
      '',
      'Apply these guidelines when drafting:',
      sprintf('- %s', $this->getTermPrompt($audience)),
      sprintf('- %s', $this->getTermPrompt($tone)),
    ]);
  }

  /**
   * Loads the prompt-ready terms from a vocabulary.
   *
   * @param string $vid
   *   The vocabulary machine name.
   *
   * @return array<int, array{id: string, name: string, ai_prompt: string}>
   *   The prompt-ready taxonomy terms.
   */
  protected function loadVocabularyTerms(string $vid): array {
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vid,
      'status' => 1,
    ],
    );
    if ($terms === []) {
      return [];
    }

    $values = [];
    foreach ($terms as $term) {
      $ai_prompt = $this->getTermPrompt($term);
      if ($ai_prompt != '') {
        $values[] = [
          'id' => (string) $term->id(),
          'name' => $term->label(),
          'ai_prompt' => $ai_prompt,
        ];
      }
    }

    return $values;
  }

  /**
   * Loads one taxonomy term and validates its vocabulary.
   *
   * @param string $termId
   *   The taxonomy term ID.
   * @param string $vid
   *   The expected vocabulary machine name.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The loaded term.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the term does not exist or belongs to another vocabulary.
   */
  protected function loadVocabularyTerm(string $termId, string $vid): TermInterface {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termId);
    if (!$term instanceof TermInterface || $term->bundle() !== $vid) {
      throw new \InvalidArgumentException(
        sprintf('Taxonomy term "%s" does not exist in vocabulary "%s".', $termId, $vid)
      );
    }
    $ai_prompt = $this->getTermPrompt($term);
    if (empty($ai_prompt)) {
      throw new \InvalidArgumentException(
        sprintf('Taxonomy term "%s" does not have a prompt defined.', $termId)
      );
    }
    return $term;
  }

  /**
   * Formats prompt choices as bullet lines.
   *
   * @param array<int, array{id: string, name: string, ai_prompt: string}> $options
   *   The prompt options.
   *
   * @return string[]
   *   The formatted bullet lines.
   */
  protected function formatPromptOptions(array $options): array {
    $lines = [];
    foreach ($options as $option) {
      $lines[] = sprintf('- %s: %s', $option['name'], $option['ai_prompt']);
    }

    return $lines;
  }

  /**
   * Returns the LLM-facing prompt text for a term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The taxonomy term.
   *
   * @return string
   *   The prompt text.
   */
  protected function getTermPrompt(TermInterface $term): string {
    return trim((string) $term->get('field_ai_prompt')->value);
  }

}
