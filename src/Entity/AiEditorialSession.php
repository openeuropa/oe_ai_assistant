<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI editorial session entity.
 *
 * @ContentEntityType(
 *   id = "ai_editorial_session",
 *   label = @Translation("AI editorial session"),
 *   label_collection = @Translation("AI editorial sessions"),
 *   label_singular = @Translation("AI editorial session"),
 *   label_plural = @Translation("AI editorial sessions"),
 *   bundle_label = @Translation("AI editorial session type"),
 *   handlers = {
 *     "list_builder" = "Drupal\oe_ai_assistant\AiEditorialSessionListBuilder",
 *     "access" = "Drupal\oe_ai_assistant\AiEditorialSessionAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\oe_ai_assistant\Form\AiEditorialSessionAddForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\oe_ai_assistant\Routing\AiEditorialSessionHtmlRouteProvider",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "bundle" = "type",
 *     "label" = "label",
 *     "owner" = "uid",
 *   },
 *   base_table = "ai_editorial_session",
 *   admin_permission = "administer ai editorial sessions",
 *   collection_permission = "view_update own sessions",
 *   bundle_entity_type = "ai_editorial_session_type",
 *   links = {
 *     "canonical" = "/admin/content/ai/{ai_editorial_session}",
 *     "add-page" = "/admin/content/ai/add",
 *     "add-form" = "/admin/content/ai/add/{ai_editorial_session_type}",
 *     "delete-form" = "/admin/content/ai/{ai_editorial_session}/delete",
 *     "collection" = "/admin/content/ai",
 *   },
 * )
 */
class AiEditorialSession extends ContentEntityBase implements AiEditorialSessionInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Session status: active.
   */
  public const STATUS_ACTIVE = 'active';

  /**
   * Session status: completed.
   */
  public const STATUS_COMPLETED = 'completed';

  /**
   * Session status: abandoned.
   */
  public const STATUS_ABANDONED = 'abandoned';

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }

    if ($this->get('status')->isEmpty()) {
      $this->set('status', self::STATUS_ACTIVE);
    }

    if ($this->get('label')->isEmpty()) {
      $bundle_label = $this->bundle();
      $date = \Drupal::service('date.formatter')->format(
        \Drupal::time()->getRequestTime(),
        'custom',
        'Y-m-d'
      );
      $this->set('label', ucfirst($bundle_label) . ' - ' . $date);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getContentType(): string {
    return (string) $this->get('content_type')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): AiEditorialSessionInterface {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['type']
      ->setLabel(t('Session type'))
      ->setDescription(t('The AI editorial session bundle.'));

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('The human-readable label for the session.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setDescription(t('The user who created the session.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setDescription(t('The session lifecycle status.'))
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

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the session was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the session was last edited.'));

    return $fields;
  }

}
