<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the AI interaction log entity.
 *
 * @ContentEntityType(
 *   id = "ai_interaction_log",
 *   label = @Translation("AI interaction log"),
 *   label_collection = @Translation("AI interaction logs"),
 *   label_singular = @Translation("AI interaction log"),
 *   label_plural = @Translation("AI interaction logs"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\oe_ai_assistant\AiInteractionLogListBuilder",
 *     "access" = "Drupal\oe_ai_assistant\AiInteractionLogAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "idempotency_key",
 *   },
 *   base_table = "ai_interaction_log",
 *   admin_permission = "administer ai interaction logs",
 *   collection_permission = "administer ai interaction logs",
 *   links = {
 *     "canonical" = "/admin/reports/ai-interaction-logs/{ai_interaction_log}",
 *     "collection" = "/admin/reports/ai-interaction-logs",
 *   },
 * )
 */
class AiInteractionLog extends ContentEntityBase implements AiInteractionLogInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->get('idempotency_key')->isEmpty()) {
      $this->set('idempotency_key', $this->buildIdempotencyKey());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderRequestId(): ?string {
    $value = $this->get('provider_request_id')->value;
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdempotencyKey(): string {
    return (string) $this->get('idempotency_key')->value;
  }

  /**
   * Builds a deterministic key for replay-safe persistence.
   */
  protected function buildIdempotencyKey(): string {
    $provider_request_id = $this->getProviderRequestId();
    $timestamp = (string) ($this->get('event_timestamp')->value ?? '');

    if ($provider_request_id !== NULL && $provider_request_id !== '') {
      return hash('sha256', implode(':', [
        $provider_request_id,
        $timestamp,
      ]));
    }

    $payload = [
      'event_name' => $this->get('event_name')->value,
      'provider' => $this->get('provider')->value,
      'model' => $this->get('model')->value,
      'operation_type' => $this->get('operation_type')->value,
      'channel' => $this->get('channel')->value,
      'event_timestamp' => $this->get('event_timestamp')->value,
      'raw_payload' => $this->get('raw_payload')->value,
    ];

    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['idempotency_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Idempotency key'))
      ->setDescription(t('Deterministic key used to avoid duplicate log writes.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayConfigurable('view', TRUE);

    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider'))
      ->setDescription(t('The AI provider that handled the interaction.'))
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('view', TRUE);

    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model'))
      ->setDescription(t('The provider model that handled the interaction.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['event_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Event name'))
      ->setDescription(t('The observability event name.'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('ai.post_generate_response')
      ->setDisplayConfigurable('view', TRUE);

    $fields['operation_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Operation type'))
      ->setDescription(t('The type of AI operation that produced the log.'))
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('view', TRUE);

    $fields['channel'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Channel'))
      ->setDescription(t('The logging channel that produced the event.'))
      ->setSetting('max_length', 128)
      ->setDefaultValue('ai_observability')
      ->setDisplayConfigurable('view', TRUE);

    $fields['provider_request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider request ID'))
      ->setDescription(t('The provider request identifier, when supplied.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['provider_parent_request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider parent request ID'))
      ->setDescription(t('The provider parent request identifier, when supplied.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    foreach ([
      'input_tokens' => t('Input tokens'),
      'output_tokens' => t('Output tokens'),
      'total_tokens' => t('Total tokens'),
      'cached_tokens' => t('Cached tokens'),
      'reasoning_tokens' => t('Reasoning tokens'),
    ] as $field_name => $label) {
      $fields[$field_name] = BaseFieldDefinition::create('integer')
        ->setLabel($label)
        ->setDescription(t('Token usage reported by the provider, if available.'))
        ->setSetting('unsigned', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['request_uri'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Request URI'))
      ->setDescription(t('The URI that was active when the interaction was captured.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['referer'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Referer'))
      ->setDescription(t('The HTTP referer that was active when the interaction was captured.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['base_url'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Base URL'))
      ->setDescription(t('The base URL that was active when the interaction was captured.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['user_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('User ID'))
      ->setDescription(t('The scalar user ID reported in the observability payload.'))
      ->setSetting('unsigned', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['ip'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IP address'))
      ->setDescription(t('The client IP address reported in the observability payload.'))
      ->setSetting('max_length', 45)
      ->setDisplayConfigurable('view', TRUE);

    $fields['severity'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Severity'))
      ->setDescription(t('The log severity reported in the observability payload.'))
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('view', TRUE);

    $fields['event_timestamp'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Event timestamp'))
      ->setDescription(t('The timestamp reported by the observability payload.'))
      ->setSetting('unsigned', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    foreach ([
      'tags' => t('Tags'),
      'guardrails' => t('Guardrails'),
      'configuration' => t('Configuration'),
      'metadata' => t('Metadata'),
      'input' => t('Input'),
      'output' => t('Output'),
      'raw_payload' => t('Raw payload'),
    ] as $field_name => $label) {
      $fields[$field_name] = BaseFieldDefinition::create('string_long')
        ->setLabel($label)
        ->setDescription(t('Raw captured value or JSON-encoded observability data.'))
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the log record was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the log record was last edited.'));

    return $fields;
  }

}
