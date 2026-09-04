<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI content provenance entity.
 *
 * Links an AI-assisted revision to the session and message that produced it,
 * with snapshots of the drafting turn's token usage, provider and model, the
 * session template, and the revision's entity_version value.
 *
 * @ContentEntityType(
 *   id = "ai_content_provenance",
 *   label = @Translation("AI content provenance"),
 *   label_collection = @Translation("AI content provenances"),
 *   label_singular = @Translation("AI content provenance"),
 *   label_plural = @Translation("AI content provenances"),
 *   handlers = {
 *     "storage" = "Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorage",
 *     "storage_schema" = "Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorageSchema",
 *     "access" = "Drupal\oe_ai_assistant\AiContentProvenanceAccessControlHandler",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   base_table = "ai_content_provenance",
 *   admin_permission = "administer ai content provenance",
 *   label_count = {
 *     "singular" = "@count AI content provenance",
 *     "plural" = "@count AI content provenances",
 *   },
 * )
 */
class AiContentProvenance extends ContentEntityBase implements AiContentProvenanceInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return sprintf('%s %d revision %d',
      $this->getTrackedEntityTypeId(),
      $this->getTrackedEntityId(),
      $this->getTrackedRevisionId(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTrackedEntityTypeId(): string {
    return (string) $this->get('entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrackedEntityId(): int {
    return (int) $this->get('entity_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTrackedRevisionId(): int {
    return (int) $this->get('revision_id')->value;
  }

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
  public function getMessage(): ?AiConversationMessageInterface {
    $message = $this->get('message')->entity;
    return $message instanceof AiConversationMessageInterface ? $message : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplateId(): ?string {
    $id = $this->get('template')->target_id;
    return $id === NULL || $id === '' ? NULL : (string) $id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTokenUsage(): array {
    return [
      'input' => (int) $this->get('tokens_input')->value,
      'output' => (int) $this->get('tokens_output')->value,
      'total' => (int) $this->get('tokens_total')->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    return (string) $this->get('provider')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getModel(): string {
    return (string) $this->get('model')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): array {
    return [
      'major' => $this->getIntOrNull('version_major'),
      'minor' => $this->getIntOrNull('version_minor'),
      'patch' => $this->getIntOrNull('version_patch'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Returns an integer field value, or NULL when the field is empty.
   */
  private function getIntOrNull(string $field): ?int {
    $value = $this->get($field)->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tracked entity type'))
      ->setDescription(t('The entity type that owns the tracked revision.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Tracked entity ID'))
      ->setDescription(t('The entity ID that owns the tracked revision.'))
      ->setRequired(TRUE);

    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Tracked revision ID'))
      ->setDescription(t('The revision ID that was produced with AI assistance.'))
      ->setRequired(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who saved the tracked revision.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Editorial session'))
      ->setDescription(t('The editorial session that produced the revision. Cleared when the session is deleted.'))
      ->setSetting('target_type', 'ai_editorial_session');

    $fields['message'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Triggering message'))
      ->setDescription(t('The assistant message that triggered the revision. Cleared when the message is deleted.'))
      ->setSetting('target_type', 'ai_conversation_message');

    $fields['template'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Drafting template'))
      ->setDescription(t('The drafting template used for the revision.'))
      ->setSetting('target_type', 'ai_drafting_template');

    $fields['tokens_input'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Input tokens'))
      ->setDescription(t('Input tokens across the drafting turn and its sub-agent tree.'));

    $fields['tokens_output'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Output tokens'))
      ->setDescription(t('Output tokens across the drafting turn and its sub-agent tree.'));

    $fields['tokens_total'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total tokens'))
      ->setDescription(t('Total tokens across the drafting turn and its sub-agent tree.'));

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider'))
      ->setDescription(t('The provider used by the triggering assistant turn.'))
      ->setSetting('max_length', 255);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model'))
      ->setDescription(t('The model used by the triggering assistant turn.'))
      ->setSetting('max_length', 255);

    $fields['version_major'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version major'))
      ->setDescription(t('Snapshot of the entity_version major number, when the tracked revision has one.'));

    $fields['version_minor'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version minor'))
      ->setDescription(t('Snapshot of the entity_version minor number, when the tracked revision has one.'));

    $fields['version_patch'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version patch'))
      ->setDescription(t('Snapshot of the entity_version patch number, when the tracked revision has one.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the provenance record was created.'));

    return $fields;
  }

}
