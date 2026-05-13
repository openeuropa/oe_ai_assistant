<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Hook implementations for the OpenEuropa AI Editorial Assistant module.
 */
final class OeAiAssistantHooks {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_query_ai_editorial_session_access_alter().
   */
  #[Hook('query_ai_editorial_session_access_alter')]
  public function queryAiEditorialSessionAccessAlter(AlterableInterface $query): void {
    if (!$query instanceof SelectInterface) {
      return;
    }

    if ($this->currentUser->hasPermission('administer ai editorial sessions')) {
      return;
    }
    // List `ai_editorial_session` entities the created by the user.
    $access = $query->orConditionGroup()
      ->condition('base_table.uid', (int) $this->currentUser->id());
    $query->condition($access);
  }

}
