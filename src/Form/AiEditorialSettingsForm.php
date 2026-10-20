<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
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
      '#description' => $this->t('Notice shown to users during AI-assisted content creation. Allowed HTML tags: %tags', ['%tags' => TransparencyNoticeFilteredMarkup::displayAllowedTags()]),
      '#config_target' => 'oe_ai_assistant.settings:transparency_notice',
      '#rows' => 4,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $value = (string) $form_state->getValue('transparency_notice');
    $filtered_value = Xss::filter($value, TransparencyNoticeFilteredMarkup::ALLOWED_TAGS);
    if (Html::serialize(Html::load($value)) !== Html::serialize(Html::load($filtered_value))) {
      $form_state->setErrorByName(
        'transparency_notice',
        $this->t('The transparency notice contains HTML tags or attributes that are not allowed. Allowed tags: %tags', ['%tags' => TransparencyNoticeFilteredMarkup::displayAllowedTags()]),
      );
    }
    if (Html::serialize(Html::load($filtered_value)) !== $filtered_value) {
      $form_state->setErrorByName(
        'transparency_notice',
        $this->t('The transparency notice is not well-formed.'),
      );
    }

  }

}
