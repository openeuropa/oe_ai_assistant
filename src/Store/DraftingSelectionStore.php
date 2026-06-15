<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Private temp-store implementation for drafting audience/tone selections.
 */
class DraftingSelectionStore implements DraftingSelectionStoreInterface {

  /**
   * The private temp-store collection name.
   */
  private const COLLECTION = 'oe_ai_drafting_selection';

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(array $context, string $optionType): ?string {
    $data = $this->tempStoreFactory
      ->get(self::COLLECTION)
      ->get($this->buildKey($context));

    if (!is_array($data) || !is_string($data[$optionType] ?? NULL)) {
      return NULL;
    }

    return $data[$optionType];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $context, string $optionType, string $selectedId): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $key = $this->buildKey($context);
    $data = $store->get($key);
    if (!is_array($data)) {
      $data = [];
    }
    $data[$optionType] = $selectedId;
    $store->set($key, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $context, string $optionType): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $key = $this->buildKey($context);
    $data = $store->get($key);
    if (!is_array($data) || !array_key_exists($optionType, $data)) {
      return;
    }
    unset($data[$optionType]);
    if ($data === []) {
      $store->delete($key);
      return;
    }
    $store->set($key, $data);
  }

  /**
   * Builds a stable temp-store key for the drafting context.
   *
   * @param array{threadId?: string, entityTypeId: string, bundle: string, sessionId?: string} $context
   *   The drafting context.
   *
   * @return string
   *   The private temp-store key.
   */
  private function buildKey(array $context): string {
    if (!empty($context['sessionId'])) {
      return 'session:' . $context['sessionId'];
    }
    if (!empty($context['threadId'])) {
      return 'thread:' . $context['threadId'];
    }

    return 'context:' . $context['entityTypeId'] . ':' . $context['bundle'];
  }

}
