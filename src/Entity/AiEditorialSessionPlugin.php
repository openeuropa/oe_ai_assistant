<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the AI editorial session plugin entity.
 *
 * @ContentEntityType(
 *   id = "ai_editorial_session_plugin",
 *   label = @Translation("AI editorial session plugin"),
 *   label_collection = @Translation("AI editorial session plugins"),
 *   label_singular = @Translation("AI editorial session plugin"),
 *   label_plural = @Translation("AI editorial session plugins"),
 *   handlers = {
 *     "access" = "Drupal\oe_ai_assistant\AiEditorialSessionPluginAccessControlHandler",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   base_table = "ai_editorial_session_plugin",
 *   admin_permission = "administer ai editorial sessions",
 * )
 */
class AiEditorialSessionPlugin extends ContentEntityBase implements AiEditorialSessionPluginInterface {

  use EntityChangedTrait;

  /**
   * Plugin instance status: active.
   */
  public const STATUS_ACTIVE = 'active';

  /**
   * Plugin instance status: completed.
   */
  public const STATUS_COMPLETED = 'completed';

  /**
   * Plugin instance status: abandoned.
   */
  public const STATUS_ABANDONED = 'abandoned';

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->get('status')->isEmpty()) {
      $this->set('status', self::STATUS_ACTIVE);
    }

    if ($this->get('configuration')->isEmpty()) {
      $this->set('configuration', []);
    }

    if ($this->get('state')->isEmpty()) {
      $this->set('state', []);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Session'))
      ->setDescription(t('The AI editorial session this plugin instance belongs to.'))
      ->setSetting('target_type', 'ai_editorial_session')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['plugin_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Plugin ID'))
      ->setDescription(t('The editorial assistant plugin machine name.'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setDescription(t('The plugin instance lifecycle status.'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          self::STATUS_ACTIVE => 'Active',
          self::STATUS_COMPLETED => 'Completed',
          self::STATUS_ABANDONED => 'Abandoned',
        ],
      ])
      ->setDefaultValue(self::STATUS_ACTIVE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['configuration'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Configuration'))
      ->setDescription(t('Stable plugin setup data for this session.'))
      ->setDefaultValue([])
      ->setDisplayConfigurable('view', TRUE);

    $fields['state'] = BaseFieldDefinition::create('map')
      ->setLabel(t('State'))
      ->setDescription(t('Runtime plugin state for this session.'))
      ->setDefaultValue([])
      ->setDisplayConfigurable('view', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('The plugin instance ordering weight within the session.'))
      ->setDefaultValue(0)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the plugin instance was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the plugin instance was last edited.'));

    return $fields;
  }

}
