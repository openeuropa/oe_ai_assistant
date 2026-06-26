<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Defines the AI conversation message entity.
 *
 * Non-bundleable, domain-agnostic store for a single LLM conversation message.
 * It back-references its owning entity generically via owner_entity_type +
 * owner_entity_id, so any module can own a conversation. A conversation is all
 * rows sharing an owner; the sub-agent tree is built from the parent
 * self-reference.
 *
 * @ContentEntityType(
 *   id = "ai_conversation_message",
 *   label = @Translation("AI conversation message"),
 *   label_collection = @Translation("AI conversation messages"),
 *   label_singular = @Translation("AI conversation message"),
 *   label_plural = @Translation("AI conversation messages"),
 *   handlers = {
 *     "storage_schema" = "Drupal\oe_ai_assistant\Entity\Storage\AiConversationMessageStorageSchema",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   base_table = "ai_conversation_message",
 * )
 */
class AiConversationMessage extends ContentEntityBase implements AiConversationMessageInterface {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Stamp the send time if the caller did not set it.
    if ($this->get('created')->isEmpty()) {
      $this->set('created', gmdate(
        DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
        \Drupal::time()->getRequestTime()
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRole(): string {
    return (string) $this->get('role')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerEntityType(): string {
    return (string) $this->get('owner_entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerEntityId(): ?int {
    $value = $this->get('owner_entity_id')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getParentId(): ?int {
    $value = $this->get('parent')->target_id;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Generic owner back-reference, set on every message (root and sub-agent).
    // The whole conversation loads in one query. Not a Drupal "owner" key (that
    // is a user); this points at any owning entity.
    $fields['owner_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Owner entity type'))
      ->setDescription(t('The entity type id of the entity that owns this conversation.'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);

    $fields['owner_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Owner entity id'))
      ->setDescription(t('The id of the entity that owns this conversation.'))
      ->setRequired(TRUE);

    // Self-reference building the sub-agent tree; NULL for a top-level turn.
    // One-way (child to parent) by design; do not add a reverse children
    // field (it would force a read-modify-write of the parent per insert).
    $fields['parent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Parent message'))
      ->setDescription(t('The message that spawned this one (sub-agent / nested call); empty for a top-level turn.'))
      ->setSetting('target_type', 'ai_conversation_message');

    $fields['role'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Role'))
      ->setDescription(t('The message role.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::ROLE_SYSTEM => 'System',
        self::ROLE_USER => 'User',
        self::ROLE_ASSISTANT => 'Assistant',
        self::ROLE_TOOL => 'Tool',
        self::ROLE_ERROR => 'Error',
      ]);

    // Author of the message, set for user-role messages.
    // NULL for agent-produced messages (assistant / system / tool).
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who sent this message, for user-role messages.'))
      ->setSetting('target_type', 'user');

    $fields['agent_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Agent id'))
      ->setDescription(t('Which agent produced this message (orchestrator or a sub-agent id).'))
      ->setSetting('max_length', 255);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setDescription(t('The message text.'));

    // JSON payloads stored as plain long strings.
    // Never queried into; the store/recorder owns (de)serialization.
    $fields['tool_calls'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Tool calls'))
      ->setDescription(t('JSON: tool name, arguments, result, tool_call_id.'));

    $fields['token_usage'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Token usage'))
      ->setDescription(t('JSON: input / output / total / reasoning / cached tokens.'));

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider'))
      ->setDescription(t('The AI provider id (e.g. mistral).'))
      ->setSetting('max_length', 255);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model'))
      ->setDescription(t('The model used (e.g. mistral-large-latest).'))
      ->setSetting('max_length', 255);

    $fields['request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Request id'))
      ->setDescription(t('Optional provider request/response id for external log correlation.'))
      ->setSetting('max_length', 255);

    $fields['latency_ms'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Latency (ms)'))
      ->setDescription(t('Per-message latency in milliseconds.'));

    $fields['finish_reason'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Finish reason'))
      ->setDescription(t('The provider finish reason.'))
      ->setSetting('max_length', 255);

    $fields['metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Metadata'))
      ->setDescription(t('JSON debug catch-all (guardrails, rate limits, raw output, requested schema, etc.).'));

    $fields['created'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the message was sent.'))
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATETIME)
      ->setRequired(TRUE);

    return $fields;
  }

}
