<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Tika server connection.
 */
final class TikaSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'document_loader_tika_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['document_loader_tika.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Tika server URL'),
      '#description' => $this->t('Base URL of the Tika server, for example http://tika:9998.'),
      '#config_target' => 'document_loader_tika.settings:url',
      '#required' => TRUE,
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout'),
      '#description' => $this->t('Seconds to wait for one extraction.'),
      '#config_target' => 'document_loader_tika.settings:timeout',
      '#min' => 1,
      '#max' => 600,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

}
