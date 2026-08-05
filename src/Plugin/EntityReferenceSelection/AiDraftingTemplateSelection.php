<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Restricts drafting templates to a session's bundle and enabled status.
 *
 * Candidates are the enabled ai_drafting_template config entities whose
 * content_type matches the host editorial session's content type. When no host
 * session is available the query returns no candidates.
 */
#[EntityReferenceSelection(
  id: 'ai_drafting_template_selection',
  label: new TranslatableMarkup('AI drafting template selection'),
  entity_types: ['ai_drafting_template'],
  group: 'ai_drafting_template_selection',
  weight: 0,
)]
class AiDraftingTemplateSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    $host = $this->getConfiguration()['entity'] ?? NULL;
    if (!$host instanceof AiEditorialSessionInterface) {
      // Without a host session, match nothing.
      $target_type = $this->getConfiguration()['target_type'];
      $id_key = $this->entityTypeManager->getDefinition($target_type)->getKey('id');
      $query->condition($id_key, NULL, '=');
      return $query;
    }

    $query->condition('status', TRUE);
    $query->condition('content_type', $host->getContentType());
    return $query;
  }

}
