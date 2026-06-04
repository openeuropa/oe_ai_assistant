<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
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
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->get('label')->value ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Label for the session.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
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
   * @param array<string, string> $content_types
   *   The content types to filter by create access permissions.
   *
   * @return array<string, string>
   *   A map of node type IDs to labels.
   */
  protected function checkTypesAccess(array $content_types): array {
    $accessHandler = $this->entityTypeManager->getAccessControlHandler('node');
    $options = [];
    foreach ($content_types as $type => $label) {
      if ($accessHandler->createAccess($type)) {
        $options[$type] = $label;
      }
    }

    return $options;
  }

}
