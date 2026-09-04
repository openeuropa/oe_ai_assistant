<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenanceInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorageInterface;

/**
 * Hooks maintaining and surfacing AI content provenance records.
 */
final class ContentProvenanceHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_ai_editorial_session_delete().
   *
   * Clears the session and message references.
   */
  #[Hook('ai_editorial_session_delete')]
  public function clearSessionReferences(EntityInterface $entity): void {
    assert($entity instanceof AiEditorialSessionInterface);
    foreach ($this->storage()->loadForSession((int) $entity->id()) as $record) {
      $record->set('session', NULL);
      $record->set('message', NULL);
      $record->save();
    }
  }

  /**
   * Implements hook_ai_conversation_message_delete().
   */
  #[Hook('ai_conversation_message_delete')]
  public function clearMessageReference(EntityInterface $entity): void {
    assert($entity instanceof AiConversationMessageInterface);
    foreach ($this->storage()->loadForMessage((int) $entity->id()) as $record) {
      $record->set('message', NULL);
      $record->save();
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function deleteTrackedEntity(EntityInterface $entity): void {
    if ($entity->getEntityType()->isRevisionable()) {
      $this->storage()->deleteForEntity($entity);
    }
  }

  /**
   * Implements hook_entity_revision_delete().
   */
  #[Hook('entity_revision_delete')]
  public function deleteTrackedRevision(EntityInterface $entity): void {
    if ($entity->getEntityType()->isRevisionable()) {
      $this->storage()->deleteForRevision($entity);
    }
  }

  /**
   * Implements hook_preprocess_table().
   *
   * Marks AI-assisted revisions on the core node revision history table.
   */
  #[Hook('preprocess_table')]
  public function markRevisionHistory(array &$variables): void {
    $classes = $variables['attributes']['class'] ?? [];
    if (!is_array($classes) || !in_array('node-revision-table', $classes, TRUE)) {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || !isset($variables['rows']) || !is_array($variables['rows'])) {
      return;
    }

    $revision_ids = [];
    foreach ($variables['rows'] as $index => $row) {
      $revision_id = $this->rowRevisionId($row, $node);
      if ($revision_id !== NULL) {
        $revision_ids[$index] = $revision_id;
      }
    }

    $cacheability = CacheableMetadata::createFromRenderArray($variables)
      ->addCacheTags(['ai_content_provenance_list'])
      ->addCacheableDependency($node);

    $records = $this->storage()->loadForRevisions('node', (int) $node->id(), array_values($revision_ids));
    if ($records !== []) {
      $variables['#attached']['library'][] = 'oe_ai_assistant/content_provenance';
    }
    foreach ($revision_ids as $index => $revision_id) {
      if (!isset($records[$revision_id])) {
        continue;
      }
      $cell = &$variables['rows'][$index]['cells'][0]['content'];
      $cell = [
        'content' => is_array($cell) ? $cell : ['#markup' => $cell],
        'provenance' => $this->buildMarker($records[$revision_id], $cacheability),
      ];
      unset($cell);
    }

    $cacheability->applyTo($variables);
  }

  /**
   * Builds the marker for one tracked revision.
   */
  private function buildMarker(AiContentProvenanceInterface $record, CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($record);

    $marker = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['ai-content-provenance']],
      'badge' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['ai-content-provenance-badge']],
        '#value' => $this->t('AI-assisted'),
      ],
    ];

    $session = $record->getSession();
    if ($session === NULL) {
      return $marker;
    }
    $access = $session->access('view', NULL, TRUE);
    $cacheability->addCacheableDependency($access);
    if ($access->isAllowed()) {
      $marker['session'] = [
        '#type' => 'link',
        '#title' => $this->t('Session'),
        '#url' => $session->toUrl(),
        '#prefix' => ' ',
      ];
    }
    return $marker;
  }

  /**
   * Reads the revision id a revision history row was built for.
   *
   * The table carries no revision ids. Non-current rows link to the
   * entity.node.revision route and the id is read from that link; the current
   * row links to the node and matches its default revision id.
   *
   * @param array $row
   *   A preprocessed table row.
   * @param \Drupal\node\NodeInterface $node
   *   The node whose history is shown.
   *
   * @return int|null
   *   The revision id, or NULL when the row carries none.
   */
  private function rowRevisionId(array $row, NodeInterface $node): ?int {
    if (isset($row['attributes']) && $row['attributes']->hasClass('revision-current')) {
      return (int) $node->getRevisionId();
    }
    $link = $row['cells'][0]['content']['#context']['date'] ?? NULL;
    if ($link !== NULL && preg_match('#/revisions/(\d+)/view#', (string) $link, $matches)) {
      return (int) $matches[1];
    }
    return NULL;
  }

  /**
   * Returns the provenance storage handler.
   */
  private function storage(): AiContentProvenanceStorageInterface {
    $storage = $this->entityTypeManager->getStorage('ai_content_provenance');
    assert($storage instanceof AiContentProvenanceStorageInterface);
    return $storage;
  }

}
