<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\document_loader_tika\TikaClient;
use Drupal\document_loader_tika\TikaExecutableClient;
use Drupal\document_loader_tika\TikaServerClient;

/**
 * Reports the Tika extraction sources on the status report.
 */
final class RequirementsHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly TikaClient $client,
    private readonly TikaServerClient $serverClient,
    private readonly TikaExecutableClient $executableClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array
   *   The requirement entries keyed by module name.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $settings = $this->configFactory->get('document_loader_tika.settings');
    $url = (string) $settings->get('url');
    $path = (string) $settings->get('jar_path');
    $server_version = $this->serverClient->version();
    $executable_version = $this->executableClient->version();
    // Extraction only needs one source, so a single reachable one is enough
    // and the other being down is informational.
    $any_reachable = $server_version !== NULL || $executable_version !== NULL;
    $active = $this->client->client();

    return [
      'document_loader_tika_server' => [
        'title' => $this->t('Apache Tika server') . ($active instanceof TikaServerClient ? ' (' . $this->t('Active') . ')' : ''),
        'value' => $server_version ?? $this->t('Not reachable at @url', ['@url' => $url]),
        'description' => $server_version === NULL
          ? $this->t('Not reachable. Check the Document Loader settings.')
          : $this->t('Reachable at @url', ['@url' => $url]),
        'severity' => $this->severity($server_version, $any_reachable),
      ],
      'document_loader_tika_app' => [
        'title' => $this->t('Apache Tika app') . ($active instanceof TikaExecutableClient ? ' (' . $this->t('Active') . ')' : ''),
        'value' => $executable_version ?? $this->t('Not reachable at @path', ['@path' => $path]),
        'description' => $executable_version === NULL
          ? $this->t('Not reachable. Check the Document Loader settings.')
          : $this->t('Reachable at @path', ['@path' => $path]),
        'severity' => $this->severity($executable_version, $any_reachable),
      ],
    ];
  }

  /**
   * Grades one source given whether any source answered.
   *
   * @param string|null $version
   *   The version the source reported, NULL when it did not answer.
   * @param bool $any_reachable
   *   Whether at least one of the two sources answered.
   */
  private function severity(?string $version, bool $any_reachable): RequirementSeverity {
    if ($version !== NULL) {
      return RequirementSeverity::OK;
    }

    return $any_reachable ? RequirementSeverity::Info : RequirementSeverity::Error;
  }

}
