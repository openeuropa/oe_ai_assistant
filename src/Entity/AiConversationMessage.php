<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI conversation message entity.
 *
 * Non-bundleable, domain-agnostic store for a single LLM conversation message.
 * It back-references its host entity generically via host_entity_type +
 * host_entity_id, so any module can host a conversation. A conversation is all
 * rows sharing a host; the sub-agent tree is built from the parent
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
 *     "list_builder" = "Drupal\oe_ai_assistant\AiConversationMessageListBuilder",
 *     "access" = "Drupal\oe_ai_assistant\AiConversationMessageAccessControlHandler",
 *     "form" = {
 *       "add" = "Drupal\oe_ai_assistant\Form\AiConversationMessageForm",
 *       "edit" = "Drupal\oe_ai_assistant\Form\AiConversationMessageForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   base_table = "ai_conversation_message",
 *   admin_permission = "administer ai conversation messages",
 *   links = {
 *     "collection" = "/admin/config/ai-editorial/messages",
 *     "add-form" = "/admin/config/ai-editorial/messages/add",
 *     "edit-form" = "/admin/config/ai-editorial/messages/{ai_conversation_message}/edit",
 *     "delete-form" = "/admin/config/ai-editorial/messages/{ai_conversation_message}/delete",
 *   },
 * )
 */
class AiConversationMessage extends ContentEntityBase implements AiConversationMessageInterface {

  use EntityOwnerTrait;

  /**
   * Base fields whose values are stored as JSON.
   */
  private const JSON_FIELDS = ['tool_calls', 'metadata'];

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

    // Reject malformed JSON before it reaches storage. The typed setters always
    // write valid JSON; this guards values written directly via set().
    foreach (self::JSON_FIELDS as $field) {
      $this->decodeField($field);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    if ($this->id() === NULL) {
      return 'New conversation message';
    }
    return sprintf('Message %s (%s)', $this->id(), $this->getRole());
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
  public function getHostEntityType(): string {
    return (string) $this->get('host_entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getHostEntityId(): ?int {
    return $this->getIntOrNull('host_entity_id');
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
  public function getToolCalls(): array {
    return $this->decodeField('tool_calls');
  }

  /**
   * {@inheritdoc}
   */
  public function setToolCalls(array $tool_calls): static {
    $this->set('tool_calls', self::encodeField($tool_calls));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTokenUsage(): array {
    return [
      'input' => $this->getIntOrNull('tokens_input'),
      'output' => $this->getIntOrNull('tokens_output'),
      'total' => $this->getIntOrNull('tokens_total'),
      'reasoning' => $this->getIntOrNull('tokens_reasoning'),
      'cached' => $this->getIntOrNull('tokens_cached'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setTokenUsage(array $token_usage): static {
    $this->set('tokens_input', $token_usage['input'] ?? NULL);
    $this->set('tokens_output', $token_usage['output'] ?? NULL);
    $this->set('tokens_total', $token_usage['total'] ?? NULL);
    $this->set('tokens_reasoning', $token_usage['reasoning'] ?? NULL);
    $this->set('tokens_cached', $token_usage['cached'] ?? NULL);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    return $this->decodeField('metadata');
  }

  /**
   * {@inheritdoc}
   */
  public function setMetadata(array $metadata): static {
    $this->set('metadata', self::encodeField($metadata));
    return $this;
  }

  /**
   * Decodes a JSON-backed field to an array.
   *
   * @param string $field
   *   The field name.
   *
   * @return array
   *   The decoded value, or an empty array when the field is empty.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException
   *   When the stored value is not valid JSON or does not decode to an array.
   */
  private function decodeField(string $field): array {
    $raw = $this->get($field)->value;
    if ($raw === NULL || $raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new InvalidJsonFieldException(sprintf("Field '%s' contains invalid JSON: %s", $field, $e->getMessage()), 0, $e);
    }
    if (!is_array($decoded)) {
      throw new InvalidJsonFieldException(sprintf("Field '%s' must contain a JSON array or object.", $field));
    }
    return $decoded;
  }

  /**
   * Returns an integer field value, or NULL when empty.
   *
   * @param string $field
   *   The field name.
   *
   * @return int|null
   *   The integer value, or NULL.
   */
  private function getIntOrNull(string $field): ?int {
    $value = $this->get($field)->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * Encodes an array to a JSON string for storage.
   *
   * @param array $value
   *   The value to encode.
   *
   * @return string
   *   The JSON string.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\InvalidJsonFieldException
   *   When the value cannot be encoded to JSON.
   */
  private static function encodeField(array $value): string {
    try {
      return json_encode($value, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new InvalidJsonFieldException('Value could not be encoded to JSON: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Generic host back-reference, set on every message (root and sub-agent).
    // The whole conversation loads in one query. This is the entity the
    // conversation belongs to (any type); it is not the Drupal "owner" key,
    // which is the user author (uid) below.
    $fields['host_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Host entity type'))
      ->setDescription(t('The entity type id of the entity this conversation belongs to.'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);

    $fields['host_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Host entity id'))
      ->setDescription(t('The id of the entity this conversation belongs to.'))
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
    // Defined explicitly rather than via the trait helper so it has
    // no current-user default: agent-produced messages keep a NULL author.
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

    // Token usage normalized into columns for SQL aggregation (cost per
    // session, per model). total is provider-reported; reasoning is a subset
    // of output and cached a subset of input.
    $fields['tokens_input'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Input tokens'))
      ->setDescription(t('Prompt tokens for this call.'));

    $fields['tokens_output'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Output tokens'))
      ->setDescription(t('Completion tokens for this call.'));

    $fields['tokens_total'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total tokens'))
      ->setDescription(t('Provider-reported total tokens for this call.'));

    $fields['tokens_reasoning'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Reasoning tokens'))
      ->setDescription(t('Reasoning tokens, a subset of output when reported.'));

    $fields['tokens_cached'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Cached tokens'))
      ->setDescription(t('Cached prompt tokens, a subset of input when reported.'));

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
