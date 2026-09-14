<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\document_loader_tika\TikaClientInterface;

/**
 * Reports the Tika server on the status report.
 */
final class RequirementsHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly TikaClientInterface $client,
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
    $version = $this->client->version();

    return [
      'document_loader_tika' => [
        'title' => $this->t('Apache Tika server'),
        'value' => $version ?? $this->t('Not reachable at @url', ['@url' => $url]),
        'description' => $version === NULL
          ? $this->t('Document text extraction fails until the server answers. Check the Document Loader settings.')
          : $this->t('Reachable at @url', ['@url' => $url]),
        'severity' => $version === NULL ? RequirementSeverity::Error : RequirementSeverity::OK,
      ],
    ];
  }

}
