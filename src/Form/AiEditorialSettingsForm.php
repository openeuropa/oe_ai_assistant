<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\oe_ai_assistant\Service\TransparencyNoticeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the AI Editorial Assistant.
 */
final class AiEditorialSettingsForm extends ConfigFormBase {

  /**
   * The transparency notice service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly TransparencyNoticeInterface $transparencyNotice,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('oe_ai_assistant.transparency_notice'),
    );
  }

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
      '#description' => $this->t('Notice shown to users during AI-assisted content creation. Allowed HTML tags: b, i, a, strong, and em.'),
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
    $sanitized = $this->transparencyNotice->sanitize($value);
    if ($value !== $sanitized) {
      $form_state->setErrorByName(
        'transparency_notice',
        $this->t('The transparency notice contains HTML tags or attributes that are not allowed. Allowed tags: b, i, a, strong, and em.')
      );
    }
  }

}
