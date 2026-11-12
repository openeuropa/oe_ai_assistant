<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_ai_assistant\TransparencyNoticeFilteredMarkup;

/**
 * Settings form for the AI Editorial Assistant.
 */
final class AiEditorialSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'oe_ai_assistant_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['oe_ai_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['transparency_notice'] = [
      '#type' => 'textarea',
      '#title' => $this->t('AI transparency notice'),
      '#description' => $this->t('Notice shown to users during AI-assisted content creation. Allowed HTML tags: %tags. Other tags will be removed.', ['%tags' => TransparencyNoticeFilteredMarkup::displayAllowedTags()]),
      '#config_target' => 'oe_ai_assistant.settings:transparency_notice',
      '#required' => TRUE,
      '#rows' => 4,
    ];

    return parent::buildForm($form, $form_state);
  }

}
