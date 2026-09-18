<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Restricts node types to those with at least one enabled drafting template.
 *
 * Candidates are the node types that appear as content_type on an enabled
 * ai_drafting_template config entity. When no bundle has one, the query
 * returns no candidates.
 */
#[EntityReferenceSelection(
  id: 'ai_draftable_node_type_selection',
  label: new TranslatableMarkup('AI draftable node type selection'),
  entity_types: ['node_type'],
  group: 'ai_draftable_node_type_selection',
  weight: 0,
)]
class DraftableNodeTypeSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $query = parent::buildEntityQuery($match, $match_operator);

    $target_type = $this->getConfiguration()['target_type'];
    $id_key = $this->entityTypeManager->getDefinition($target_type)->getKey('id');

    $storage = $this->entityTypeManager->getStorage('ai_drafting_template');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', TRUE)->execute();

    $bundles = [];
    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    foreach ($storage->loadMultiple($ids) as $template) {
      $bundles[$template->getContentType()] = TRUE;
    }

    if ($bundles === []) {
      // No bundle has an enabled template; match nothing.
      $query->condition($id_key, NULL, '=');
      return $query;
    }

    $query->condition($id_key, array_keys($bundles), 'IN');
    return $query;
  }

}
