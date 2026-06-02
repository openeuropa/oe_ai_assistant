<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPlugin;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface;

/**
 * Repository for AI editorial session plugin instances.
 */
class AiEditorialSessionPluginStore {

  /**
   * Constructs an AiEditorialSessionPluginStore.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads a plugin instance for a session and plugin ID.
   */
  public function loadForSession(AiEditorialSessionInterface $session, string $plugin_id): ?AiEditorialSessionPluginInterface {
    $this->assertSavedSession($session);

    $ids = $this->getStorage()
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('session', $session->id())
      ->condition('plugin_id', $plugin_id)
      ->sort('weight')
      ->sort('id')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $entity = $this->getStorage()->load(reset($ids));
    return $entity instanceof AiEditorialSessionPluginInterface ? $entity : NULL;
  }

  /**
   * Loads or creates a plugin instance for a session and plugin ID.
   */
  public function loadOrCreateForSession(AiEditorialSessionInterface $session, string $plugin_id, array $configuration = []): AiEditorialSessionPluginInterface {
    $this->assertSavedSession($session);

    $plugin_instance = $this->loadForSession($session, $plugin_id);
    if ($plugin_instance instanceof AiEditorialSessionPluginInterface) {
      return $plugin_instance;
    }

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface $plugin_instance */
    $plugin_instance = $this->getStorage()
      ->create([
        'session' => $session->id(),
        'plugin_id' => $plugin_id,
        'configuration' => $configuration,
      ]);
    $plugin_instance->save();

    return $plugin_instance;
  }

  /**
   * Saves runtime state for a session plugin instance.
   */
  public function saveState(AiEditorialSessionInterface $session, string $plugin_id, array $state): AiEditorialSessionPluginInterface {
    $plugin_instance = $this->loadOrCreateForSession($session, $plugin_id);
    $plugin_instance->setState($state);
    $plugin_instance->save();

    return $plugin_instance;
  }

  /**
   * Deletes plugin instances for a session, optionally limited by plugin ID.
   */
  public function deleteForSession(AiEditorialSessionInterface $session, ?string $plugin_id = NULL): int {
    $this->assertSavedSession($session);

    $query = $this->getStorage()
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('session', $session->id());

    if ($plugin_id !== NULL) {
      $query->condition('plugin_id', $plugin_id);
    }

    $ids = $query->execute();
    if ($ids === []) {
      return 0;
    }

    $entities = $this->getStorage()->loadMultiple($ids);
    $this->getStorage()->delete($entities);

    return count($entities);
  }

  /**
   * Loads active plugin instances for a plugin ID.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionPluginInterface[]
   *   Active plugin instances keyed by entity ID.
   */
  public function loadActiveByPlugin(string $plugin_id): array {
    $ids = $this->getStorage()
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', $plugin_id)
      ->condition('status', AiEditorialSessionPlugin::STATUS_ACTIVE)
      ->sort('session')
      ->sort('weight')
      ->sort('id')
      ->execute();

    if ($ids === []) {
      return [];
    }

    return array_filter(
      $this->getStorage()->loadMultiple($ids),
      static fn($entity): bool => $entity instanceof AiEditorialSessionPluginInterface,
    );
  }

  /**
   * Returns the plugin instance entity storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The plugin instance entity storage.
   */
  protected function getStorage() {
    return $this->entityTypeManager->getStorage('ai_editorial_session_plugin');
  }

  /**
   * Ensures the session is saved before querying by ID.
   */
  protected function assertSavedSession(AiEditorialSessionInterface $session): void {
    if ($session->id() === NULL) {
      throw new \InvalidArgumentException('A saved AI editorial session is required.');
    }
  }

}
