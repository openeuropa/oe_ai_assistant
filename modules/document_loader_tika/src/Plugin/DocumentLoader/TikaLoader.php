<?php

declare(strict_types=1);

namespace Drupal\document_loader_tika\Plugin\DocumentLoader;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\document_loader\Attribute\DocumentLoader;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderInputInterface;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderOutputInterface;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderTypeFactory;
use Drupal\document_loader\Exception\DocumentLoaderException;
use Drupal\document_loader\Plugin\DocumentLoaderBase;
use Drupal\document_loader_tika\Exception\TikaException;
use Drupal\document_loader_tika\TikaClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts file content through an Apache Tika server.
 *
 * Tika reads every declared type, so the plugin only resolves the file
 * path, picks the Accept header for the requested output and hands the
 * response to the type factory.
 */
#[DocumentLoader(
  id: 'document_loader_tika:tika',
  label: new TranslatableMarkup('Apache Tika server'),
  description: new TranslatableMarkup('Extracts text or XHTML from files through an Apache Tika server.'),
  document_loader_types: [
    'document_loader_type:word',
    'document_loader_type:pdf',
    'document_loader_type:text',
    'document_loader_type:markdown',
    'document_loader_type:presentation',
    'document_loader_type:spreadsheet',
    'document_loader_type:html',
  ],
  output_types: ['text', 'html'],
)]
final class TikaLoader extends DocumentLoaderBase {

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\document_loader_tika\TikaClientInterface $client
   *   The Tika client.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system, resolving stream URIs to paths.
   * @param \Drupal\document_loader\DocumentLoaderType\DocumentLoaderTypeFactory $typeFactory
   *   The output factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the availability check.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly TikaClientInterface $client,
    private readonly FileSystemInterface $fileSystem,
    private readonly DocumentLoaderTypeFactory $typeFactory,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    mixed $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(TikaClientInterface::class),
      $container->get('file_system'),
      $container->get('document_loader.type_factory'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Availability is a configured server URL, not a live ping: the manager
   * asks before every load, and a server that is down surfaces as a load
   * failure and on the status report instead.
   */
  public function isAvailable(): bool {
    return trim((string) $this->configFactory->get('document_loader_tika.settings')->get('url')) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function load(
    DocumentLoaderInputInterface $input,
    string $output_format = 'text',
  ): DocumentLoaderOutputInterface {
    $uri = method_exists($input, 'getFileUri') ? $input->getFileUri() : $input->getContent();
    $path = $this->fileSystem->realpath($uri);
    if ($path === FALSE || !is_file($path)) {
      throw new DocumentLoaderException(sprintf('The file %s cannot be resolved to a local path.', $uri));
    }

    $format = $output_format === 'html' ? 'html' : 'text';
    try {
      $content = $this->client->extract($path, $format === 'html' ? 'text/html' : 'text/plain');
    }
    catch (TikaException $e) {
      throw new DocumentLoaderException($e->getMessage(), 0, $e);
    }

    return $this->typeFactory->createOutput($format, $content, [
      'source' => $uri,
      'loader' => 'tika',
    ]);
  }

}
