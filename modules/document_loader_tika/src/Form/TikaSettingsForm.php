<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Settings form for the Tika extraction source.
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
    $form['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Extraction mode'),
      '#options' => [
        'server' => $this->t('Tika server'),
        'executable' => $this->t('Local Tika executable'),
      ],
      '#description' => $this->t('Use a Tika HTTP server or run the tika-app JAR on this web server.'),
      '#config_target' => 'document_loader_tika.settings:mode',
      // Existing installations do not yet have this configuration value.
      '#default_value' => $this->config('document_loader_tika.settings')->get('mode') ?: 'server',
      '#required' => TRUE,
    ];
    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Tika server URL'),
      '#description' => $this->t('Base URL of the Tika server, for example http://tika:9998.'),
      '#config_target' => 'document_loader_tika.settings:url',
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => 'server'],
        ],
      ],
    ];
    $form['jar_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tika app JAR path'),
      '#description' => $this->t('Absolute path to the tika-app command-line JAR. The server/REST distribution cannot be used here.'),
      '#config_target' => 'document_loader_tika.settings:jar_path',
      '#states' => [
        'visible' => [
          ':input[name="mode"]' => ['value' => 'executable'],
        ],
      ],
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

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $mode = $form_state->getValue('mode');
    if (!in_array($mode, ['server', 'executable'], TRUE)) {
      $form_state->setErrorByName('mode', $this->t('Select a valid extraction mode.'));
      return;
    }

    if ($mode === 'server') {
      if (trim((string) $form_state->getValue('url')) === '') {
        $form_state->setErrorByName('url', $this->t('Enter the Tika server URL.'));
      }
      return;
    }

    $jar_path = trim((string) $form_state->getValue('jar_path'));
    if ($jar_path === '') {
      $form_state->setErrorByName('jar_path', $this->t('Enter the path to the tika-app JAR.'));
      return;
    }
    if (!is_file($jar_path) || !is_readable($jar_path)) {
      $form_state->setErrorByName('jar_path', $this->t('The Tika app JAR path must be a readable file.'));
      return;
    }

    $java = (new ExecutableFinder())->find('java');
    if ($java === NULL) {
      $form_state->setErrorByName('jar_path', $this->t('The java executable was not found on PATH.'));
      return;
    }

    $process = new Process([
      $java,
      '-Djava.awt.headless=true',
      '-Dfile.encoding=UTF-8',
      '-jar',
      $jar_path,
      '--version',
    ], NULL, [
      'LANG' => 'C.UTF-8',
      'LC_ALL' => 'C.UTF-8',
    ]);
    $process->setTimeout(2.0);

    try {
      $process->run();
    }
    catch (\Throwable) {
      $form_state->setErrorByName('jar_path', $this->t('Java could not run the Tika app JAR.'));
      return;
    }
    if (!$process->isSuccessful()) {
      $form_state->setErrorByName('jar_path', $this->t('Java could not run the Tika app JAR.'));
    }
  }

}
