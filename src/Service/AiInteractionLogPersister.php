<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface;

/**
 * Persists AI interaction log records.
 */
class AiInteractionLogPersister {

  /**
   * Constructs a new persister.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Saves a log record unless the provider request was already captured.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface|null
   *   The saved log entity, or NULL when a duplicate already exists.
   */
  public function persist(array $values): ?AiInteractionLogInterface {
    $storage = $this->entityTypeManager->getStorage('ai_interaction_log');
    $provider_request_id = $values['provider_request_id'] ?? NULL;

    if (is_string($provider_request_id) && $provider_request_id !== '') {
      $existing = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('provider_request_id', $provider_request_id)
        ->range(0, 1)
        ->execute();

      if ($existing !== []) {
        return NULL;
      }
    }

    /** @var \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface $log */
    $log = $storage->create($values);
    $log->save();

    return $log;
  }

}
