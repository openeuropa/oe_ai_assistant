<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lean creation form for AI editorial sessions.
 */
class AiEditorialSessionAddForm extends ContentEntityForm {

  use MessengerTrait;

  /**
   * The current user.
   */
  protected AccountInterface $currentUserAccount;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $form */
    $form = parent::create($container);
    $form->currentUserAccount = $container->get('current_user');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $content_type_options = $this->getCreateableContentTypeOptions();
    $has_content_type_options = $content_type_options !== [];

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#required' => TRUE,
      '#options' => $content_type_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->entity->get('content_type')->value ?: NULL,
      '#description' => $this->t('Choose the target content type for this content creation session.'),
      '#disabled' => !$has_content_type_options,
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->get('label')->value ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Label for the session.'),
    ];

    if (!$has_content_type_options) {
      $form['content_type_help'] = [
        '#type' => 'status_messages',
      ];
      $this->messenger()->addError($this->t('No content types are available for session creation because you do not have permission to create content.'));
      $form['actions']['submit']['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $this->entity->set('content_type', $form_state->getValue('content_type'));
    $this->entity->set('label', $form_state->getValue('label'));
    $this->entity->setOwnerId((int) $this->currentUserAccount->id());

    $status = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Created AI editorial session %label.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('canonical'));

    return $status;
  }

  /**
   * Returns node type options the current user can create.
   *
   * @return array<string, string>
   *   A map of node type IDs to labels.
   */
  protected function getCreateableContentTypeOptions(): array {
    $node_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    uasort($node_types, static fn (NodeTypeInterface $a, NodeTypeInterface $b): int => strnatcasecmp($a->label(), $b->label()));

    $options = [];
    foreach ($node_types as $node_type) {
      if (!$this->currentUserAccount->hasPermission(sprintf('create %s content', $node_type->id()))) {
        continue;
      }
      $options[$node_type->id()] = $node_type->label();
    }

    return $options;
  }

}
