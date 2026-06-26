<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ConfigFactoryInterface $configFactory,
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

  /**
   * Implements hook_config_schema_info_alter().
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    if (!isset($definitions['oe_ai_assistant.ai_drafting_template.*'])) {
      return;
    }

    $fieldsMapping = [];
    $defaultsMapping = [];

    foreach ($this->configFactory->listAll('oe_ai_assistant.ai_drafting_template.') as $configName) {
      $template = $this->configFactory->get($configName)->getRawData();
      $fields = $template['fields'];
      $defaults = $template['defaults'];
      $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions('node', $template['content_type']);

      // Field/default keys are field machine names stored in template config.
      foreach ($fields as $fieldName => $_field) {
        $fieldType = $fieldDefinitions[$fieldName]->getType();
        $fieldsMapping[$fieldName] = $definitions['oe_ai_assistant.ai_drafting_template.field'];
        // Inject the field item schema without storing the type in config.
        $fieldValueType = 'field.value.' . $fieldType;
        if (isset($definitions[$fieldValueType])) {
          $fieldsMapping[$fieldName]['mapping']['default_value']['sequence']['type'] = $fieldValueType;
        }
      }

      foreach ($defaults as $fieldName => $_default) {
        $fieldType = $fieldDefinitions[$fieldName]->getType();
        $defaultsMapping[$fieldName] = $definitions['oe_ai_assistant.ai_drafting_template.default'];
        $fieldValueType = 'field.value.' . $fieldType;
        if (isset($definitions[$fieldValueType])) {
          $defaultsMapping[$fieldName]['mapping']['default_value']['sequence']['type'] = $fieldValueType;
        }
      }
    }

    if ($fieldsMapping !== []) {
      $definitions['oe_ai_assistant.ai_drafting_template.*']['mapping']['fields'] = [
        'type' => 'mapping',
        'label' => 'Field definitions',
        'mapping' => $fieldsMapping,
      ];
    }

    if ($defaultsMapping !== []) {
      $definitions['oe_ai_assistant.ai_drafting_template.*']['mapping']['defaults'] = [
        'type' => 'mapping',
        'label' => 'Default values',
        'mapping' => $defaultsMapping,
      ];
    }
  }

}
