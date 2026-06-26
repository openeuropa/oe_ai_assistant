<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;

/**
 * Filter form for the AI conversation message overview.
 *
 * A GET form: submitting reloads the collection page with the filter values as
 * query parameters, which the list builder reads to constrain the query.
 */
class AiConversationMessageFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'oe_ai_assistant_conversation_message_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;

    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'container-inline';

    $form['owner_entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Owner type'),
      '#size' => 18,
      '#default_value' => $query->get('owner_entity_type', ''),
    ];
    $form['owner_entity_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Owner id'),
      '#min' => 0,
      '#default_value' => $query->get('owner_entity_id', ''),
    ];
    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#options' => [
        '' => $this->t('- Any -'),
        AiConversationMessageInterface::ROLE_SYSTEM => $this->t('System'),
        AiConversationMessageInterface::ROLE_USER => $this->t('User'),
        AiConversationMessageInterface::ROLE_ASSISTANT => $this->t('Assistant'),
        AiConversationMessageInterface::ROLE_TOOL => $this->t('Tool'),
        AiConversationMessageInterface::ROLE_ERROR => $this->t('Error'),
      ],
      '#default_value' => $query->get('role', ''),
    ];
    $form['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider'),
      '#size' => 14,
      '#default_value' => $query->get('provider', ''),
    ];
    $form['agent_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Agent'),
      '#size' => 16,
      '#default_value' => $query->get('agent_id', ''),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('entity.ai_conversation_message.collection'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET form: the browser submits the values as query parameters, which the
    // list builder reads. No server-side handling is required.
  }

}
