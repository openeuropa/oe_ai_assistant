<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\AiEditorialSessionActivityAccessControlHandler;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the activity entry of an AI editorial session.
 *
 * One entry is a single sentence saying what happened in a session and who
 * did it. AiEditorialSessionInterface::recordActivity() adds an entry, and
 * nothing updates it afterwards.
 */
#[ContentEntityType(
  id: 'ai_editorial_session_activity',
  label: new TranslatableMarkup('AI editorial session activity'),
  label_collection: new TranslatableMarkup('AI editorial session activity'),
  label_singular: new TranslatableMarkup('AI editorial session activity entry'),
  label_plural: new TranslatableMarkup('AI editorial session activity entries'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => AiEditorialSessionActivityAccessControlHandler::class,
  ],
  base_table: 'ai_editorial_session_activity',
  admin_permission: 'administer ai editorial sessions',
)]
class AiEditorialSessionActivity extends ContentEntityBase implements AiEditorialSessionActivityInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getSession(): ?AiEditorialSessionInterface {
    $session = $this->get('session')->entity;

    return $session instanceof AiEditorialSessionInterface ? $session : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): ActivityType {
    return ActivityType::from((string) $this->get('type')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage(): string {
    return (string) $this->get('message')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entry was recorded.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The user who did what the entry reports.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Type'))
      ->setDescription(t('The kind of entry, as a machine name.'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Message'))
      ->setDescription(t('One sentence saying what happened.'))
      ->setRequired(TRUE);

    $fields['session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Session'))
      ->setDescription(t('The session the entry belongs to.'))
      ->setSetting('target_type', 'ai_editorial_session')
      ->setRequired(TRUE);

    return $fields;
  }

}
