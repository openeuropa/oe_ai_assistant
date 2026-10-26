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
 * ai_drafting_template config entity, further narrowed to the ones the
 * current user may actually create: node_type is a config entity, so its
 * own query has no per-row access filtering, and 'create %s content' is an
 * account permission rather than a property this plugin's query could
 * condition on. When no bundle has one, the query returns no candidates.
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
      $query->notExists($id_key);
      return $query;
    }

    $query->condition($id_key, array_keys($bundles), 'IN');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $options = parent::getReferenceableEntities($match, $match_operator, $limit);

    $access_handler = $this->entityTypeManager->getAccessControlHandler('node');
    foreach ($options as $bundle => $bundle_options) {
      foreach (array_keys($bundle_options) as $type_id) {
        if (!$access_handler->createAccess((string) $type_id)) {
          unset($options[$bundle][$type_id]);
        }
      }
      if ($options[$bundle] === []) {
        unset($options[$bundle]);
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $valid_ids = parent::validateReferenceableEntities($ids);

    $access_handler = $this->entityTypeManager->getAccessControlHandler('node');
    return array_filter($valid_ids, fn ($id): bool => (bool) $access_handler->createAccess((string) $id));
  }

}
