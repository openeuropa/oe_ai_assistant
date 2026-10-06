<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\document_loader_tika\TikaClientInterface;
use Drupal\document_loader_tika\TikaExecutableClient;
use Drupal\document_loader_tika\TikaServerClient;

/**
 * Reports the Tika server on the status report.
 */
final class RequirementsHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly TikaClientInterface $client,
    private readonly TikaServerClient $serverClient,
    private readonly TikaExecutableClient $executableClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array
   *   The requirement entry keyed by module name.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $url = (string) $this->configFactory->get('document_loader_tika.settings')->get('url');
    $path = (string) $this->configFactory->get('document_loader_tika.settings')->get('jar_path');
    $serverVersion = $this->serverClient->version();
    $excutableVersion = $this->executableClient->version();
    $mode = $this->client->client();
    $serverActive = '';
    if ($mode instanceof TikaServerClient) {
      $serverActive = '(' . $this->t('Active') . ')';
    }
    $executableActive = '';
    if ($mode instanceof TikaExecutableClient) {
      $executableActive = '(' . $this->t('Active') . ')';
    }
    return [
      'document_loader_tika_server' => [
        'title' => $this->t('Apache Tika server') . $serverActive,
        'value' => $serverVersion ?? $this->t('Not reachable at @url', ['@url' => $url]),
        'description' => $serverVersion === NULL
          ? $this->t('Document text extraction fails until the server answers. Check the Document Loader settings.')
          : $this->t('Reachable at @url', ['@url' => $url]),
        'severity' => $serverVersion === NULL ? RequirementSeverity::Error : RequirementSeverity::OK,
      ],
      'document_loader_tika_app' => [
        'title' => $this->t('Apache Tika app') . $executableActive,
        'value' => $excutableVersion ?? $this->t('App not reachable @path', ['@path' => $path]),
        'description' => $excutableVersion === NULL
          ? $this->t('Document text extraction fails  Check the Document Loader settings.')
          : $this->t('Reachable at @path', ['@path' => $path]),
        'severity' => $excutableVersion === NULL ? RequirementSeverity::Error : RequirementSeverity::OK,
      ],
    ];
  }

}
