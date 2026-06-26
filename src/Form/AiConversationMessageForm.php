<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Add and edit form for AI conversation messages.
 *
 * Builds explicit widgets for the message fields (the entity has no configured
 * form display) so the records can be created and edited for testing.
 */
class AiConversationMessageForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $entity */
    $entity = $this->entity;

    $form['owner_entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner entity type'),
      '#required' => TRUE,
      '#default_value' => $entity->getOwnerEntityType(),
    ];
    $form['owner_entity_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Owner entity id'),
      '#min' => 0,
      '#required' => TRUE,
      '#default_value' => $entity->getOwnerEntityId(),
    ];
    $form['parent'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'ai_conversation_message',
      '#title' => $this->t('Parent message'),
      '#description' => $this->t('Leave empty for a top-level turn.'),
      '#default_value' => $entity->get('parent')->entity,
    ];
    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#required' => TRUE,
      '#options' => [
        AiConversationMessageInterface::ROLE_SYSTEM => $this->t('System'),
        AiConversationMessageInterface::ROLE_USER => $this->t('User'),
        AiConversationMessageInterface::ROLE_ASSISTANT => $this->t('Assistant'),
        AiConversationMessageInterface::ROLE_TOOL => $this->t('Tool'),
        AiConversationMessageInterface::ROLE_ERROR => $this->t('Error'),
      ],
      '#default_value' => $entity->getRole() ?: AiConversationMessageInterface::ROLE_USER,
    ];
    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Author'),
      '#description' => $this->t('Set for user-role messages.'),
      '#default_value' => $entity->get('uid')->entity,
    ];
    $form['agent_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Agent id'),
      '#default_value' => $entity->get('agent_id')->value,
    ];
    $form['content'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Content'),
      '#default_value' => $entity->get('content')->value,
    ];
    $form['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider'),
      '#default_value' => $entity->get('provider')->value,
    ];
    $form['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $entity->get('model')->value,
    ];
    $form['request_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Request id'),
      '#default_value' => $entity->get('request_id')->value,
    ];
    $form['latency_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Latency (ms)'),
      '#min' => 0,
      '#default_value' => $entity->get('latency_ms')->value,
    ];
    $form['finish_reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Finish reason'),
      '#default_value' => $entity->get('finish_reason')->value,
    ];

    $form['tokens'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Token usage'),
    ];
    foreach ($this->tokenFields() as $field => $label) {
      $form['tokens'][$field] = [
        '#type' => 'number',
        '#title' => $label,
        '#min' => 0,
        '#default_value' => $entity->get($field)->value,
      ];
    }

    $form['tool_calls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tool calls (JSON)'),
      '#default_value' => $entity->get('tool_calls')->value,
    ];
    $form['metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Metadata (JSON)'),
      '#default_value' => $entity->get('metadata')->value,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate the raw JSON fields here so the user gets a form error rather
    // than an exception from the entity's preSave().
    foreach (['tool_calls', 'metadata'] as $field) {
      $value = trim((string) $form_state->getValue($field));
      if ($value === '') {
        continue;
      }
      try {
        $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        $form_state->setErrorByName($field, $this->t('@field is not valid JSON: @error', [
          '@field' => $form[$field]['#title'],
          '@error' => $e->getMessage(),
        ]));
        continue;
      }
      if (!is_array($decoded)) {
        $form_state->setErrorByName($field, $this->t('@field must be a JSON array or object.', [
          '@field' => $form[$field]['#title'],
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $entity */
    $entity = $this->entity;

    $entity->set('owner_entity_type', $form_state->getValue('owner_entity_type'));
    $entity->set('owner_entity_id', (int) $form_state->getValue('owner_entity_id'));
    $entity->set('parent', $form_state->getValue('parent') ?: NULL);
    $entity->set('role', $form_state->getValue('role'));
    $entity->set('uid', $form_state->getValue('uid') ?: NULL);
    $entity->set('agent_id', $this->nullIfEmpty($form_state->getValue('agent_id')));
    $entity->set('content', $this->nullIfEmpty($form_state->getValue('content')));
    $entity->set('provider', $this->nullIfEmpty($form_state->getValue('provider')));
    $entity->set('model', $this->nullIfEmpty($form_state->getValue('model')));
    $entity->set('request_id', $this->nullIfEmpty($form_state->getValue('request_id')));
    $entity->set('latency_ms', $this->nullIfEmpty($form_state->getValue('latency_ms')));
    $entity->set('finish_reason', $this->nullIfEmpty($form_state->getValue('finish_reason')));

    foreach (array_keys($this->tokenFields()) as $field) {
      $entity->set($field, $this->nullIfEmpty($form_state->getValue($field)));
    }

    $entity->set('tool_calls', $this->nullIfEmpty($form_state->getValue('tool_calls')));
    $entity->set('metadata', $this->nullIfEmpty($form_state->getValue('metadata')));

    $status = $entity->save();

    $this->messenger()->addStatus($this->t('Saved conversation message %label.', [
      '%label' => $entity->label(),
    ]));
    $form_state->setRedirectUrl(Url::fromRoute('entity.ai_conversation_message.collection'));

    return $status;
  }

  /**
   * Returns the token usage fields and their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   A map of field name to label.
   */
  protected function tokenFields(): array {
    return [
      'tokens_input' => $this->t('Input'),
      'tokens_output' => $this->t('Output'),
      'tokens_total' => $this->t('Total'),
      'tokens_reasoning' => $this->t('Reasoning'),
      'tokens_cached' => $this->t('Cached'),
    ];
  }

  /**
   * Normalizes an empty submitted value to NULL.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return mixed
   *   NULL when the trimmed value is an empty string, otherwise the value.
   */
  protected function nullIfEmpty($value): mixed {
    return (is_string($value) && trim($value) === '') ? NULL : $value;
  }

}
