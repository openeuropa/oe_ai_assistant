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
 * @ContentEntityType(
 *   id = "ai_content_provenance",
 *   label = @Translation("AI content provenance"),
 *   label_collection = @Translation("AI content provenances"),
 *   label_singular = @Translation("AI content provenance"),
 *   label_plural = @Translation("AI content provenances"),
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid"
 *   },
 *   handlers = {
 *     "storage_schema" = "Drupal\oe_ai_assistant\Entity\Storage\AiContentProvenanceStorageSchema"
 *   },
 *   base_table = "ai_content_provenance",
 *   label_count = {
 *     "singular" = "@count AI content provenance",
 *     "plural" = "@count AI content provenances"
 *   }
 * )
 */
final class AiContentProvenance extends ContentEntityBase implements AiContentProvenanceInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage controller.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->getOwnerId() === NULL) {
      $this->setOwnerId(0);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   The base field definitions for the provenance entity.
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
      ->setDescription(t('The revision ID that was created by AI.'))
      ->setRequired(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who owns the provenance record.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Editorial session'))
      ->setDescription(t('The editorial session that produced the revision.'))
      ->setSetting('target_type', 'ai_editorial_session');

    $fields['message'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Triggering message'))
      ->setDescription(t('The draft-content assistant message that triggered the revision.'))
      ->setSetting('target_type', 'ai_conversation_message');

    $fields['template'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Drafting template'))
      ->setDescription(t('The drafting template used for the revision.'))
      ->setSetting('target_type', 'ai_drafting_template');

    $fields['tokens_input'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Input tokens'))
      ->setDescription(t('Aggregated input tokens for the draft subtree.'));

    $fields['tokens_output'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Output tokens'))
      ->setDescription(t('Aggregated output tokens for the draft subtree.'));

    $fields['tokens_total'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total tokens'))
      ->setDescription(t('Aggregated total tokens for the draft subtree.'));

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
      ->setDescription(t('Snapshot of the node major version.'));

    $fields['version_minor'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version minor'))
      ->setDescription(t('Snapshot of the node minor version.'));

    $fields['version_patch'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version patch'))
      ->setDescription(t('Snapshot of the node patch version.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the provenance record was created.'));

    return $fields;
  }

}
