<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\document_loader_tika\Exception\TikaException;
use Drupal\document_loader_tika\TikaExecutableClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the Tika extraction source.
 */
final class TikaSettingsForm extends ConfigFormBase {

  /**
   * Probes the submitted JAR path on behalf of the validation.
   */
  protected TikaExecutableClient $executableClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->executableClient = $container->get(TikaExecutableClient::class);

    return $instance;
  }

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

    try {
      $this->executableClient->probe($jar_path);
    }
    catch (TikaException $e) {
      $form_state->setErrorByName('jar_path', $this->t('Tika cannot use @path: @reason', [
        '@path' => $jar_path,
        '@reason' => $e->getMessage(),
      ]));
    }
  }

}
