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
    // On a rebuild, reflect the chosen content type on the entity before the
    // widgets build, so the template options are scoped to that bundle. The
    // submitted value is read from the raw input because processed form values
    // are not populated yet when form() runs.
    $input = $form_state->getUserInput();
    $chosenType = $input['content_type'] ?? NULL;
    if (is_array($chosenType)) {
      $chosenType = $chosenType[0]['target_id'] ?? ($chosenType['target_id'] ?? NULL);
    }
    // The select placeholder submits '_none'; treat it as no selection so
    // AJAX rebuilds (e.g. the context documents add-more button) do not
    // store it on the entity as a real content type.
    if (is_string($chosenType) && $chosenType !== '' && $chosenType !== '_none') {
      $this->entity->set('content_type', $chosenType);
      $this->entity->set('template', NULL);
    }

    $form = parent::form($form, $form_state);
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->get('label')->value ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Label for the session.'),
    ];

    // Regenerate the template options whenever the content type changes.
    $form['content_type']['widget']['#ajax'] = [
      'callback' => '::updateTemplateElement',
      'wrapper' => 'ai-editorial-session-template',
      'event' => 'change',
    ];
    $form['template']['#prefix'] = '<div id="ai-editorial-session-template">';
    $form['template']['#suffix'] = '</div>';

    return $form;
  }

  /**
   * AJAX callback returning the rebuilt template element.
   *
   * @param array $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The template form element, scoped to the chosen content type.
   */
  public function updateTemplateElement(array $form, FormStateInterface $form_state): array {
    return $form['template'];
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
