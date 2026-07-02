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

  /**
   * Implements hook_options_list_alter().
   *
   * Check create permissions to create the node types selector.
   *
   * @param array<int,mixed> $options
   *   Node types options.
   * @param array<int,mixed> $context
   *   Context of the call.
   */
  #[Hook('options_list_alter')]
  public function nodeTypesOptionsAccess(array &$options, array $context): void {
    if (!isset($context['entity']) || $context['entity']->getEntityType()->id() !== 'ai_editorial_session') {
      return;
    }
    $settings = $context['fieldDefinition']?->getSettings();
    if (isset($settings['handler'])
      && isset($settings['target_type'])
      && $settings['handler'] == 'default:node_type'
      && $settings['target_type'] == 'node_type'
    ) {
      $accessHandler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
      $valid_options = [];
      foreach ($options as $type => $option) {
        if ($accessHandler->createAccess($type)) {
          $valid_options[$type] = $option;
        }
      }
      $options = $valid_options;
    }
  }

}
