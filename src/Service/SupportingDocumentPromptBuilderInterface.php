<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Builds supporting-document prompt context sections.
 */
interface SupportingDocumentPromptBuilderInterface {

  /**
   * Builds the supporting-document prompt section.
   *
   * @param array<int, array{label: string, summary: string}> $summaries
   *   Labelled supporting-document summaries.
   *
   * @return string
   *   The formatted prompt section, or an empty string when there is no
   *   summary content.
   */
  public function buildSection(array $summaries): string;

}
