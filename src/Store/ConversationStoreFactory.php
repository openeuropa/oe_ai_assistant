<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Store;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Factory for creating scoped ConversationStore instances.
 */
class ConversationStoreFactory implements ConversationStoreFactoryInterface {

  /**
   * Constructs a ConversationStoreFactory.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   */
  public function __construct(
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getStore(string $collection, string $threadId): ConversationStoreInterface {
    return new ConversationStore(
      $this->tempStoreFactory->get($collection),
      $threadId,
    );
  }

}
