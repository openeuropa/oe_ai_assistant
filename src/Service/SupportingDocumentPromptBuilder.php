<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

/**
 * Builds supporting-document prompt context sections.
 */
class SupportingDocumentPromptBuilder implements SupportingDocumentPromptBuilderInterface {

  /**
   * {@inheritdoc}
   */
  public function buildSection(array $summaries): string {
    $items = [];
    $count = 1;
    foreach ($summaries as $document) {
      $label = trim(preg_replace('/\s+/', ' ', $document['label']) ?? '');
      $summary = trim($document['summary']);
      if ($summary === '') {
        continue;
      }

      $items[] = sprintf(
        'Document %d - %s: %s',
        $count,
        $label !== '' ? $label : 'Supporting document',
        $summary,
      );
      $count++;
    }

    if ($items === []) {
      return '';
    }

    return "Supporting document context:\n"
      . "Use these summaries as background source material for drafting. "
      . "Do not copy or publish them verbatim.\n"
      . implode("\n", $items);
  }

}
