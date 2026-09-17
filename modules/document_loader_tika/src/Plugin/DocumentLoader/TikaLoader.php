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
use Drupal\document_loader\DocumentLoaderType\Input\FileInput;
use Drupal\document_loader\Exception\DocumentLoaderException;
use Drupal\document_loader\Plugin\DocumentLoaderBase;
use Drupal\document_loader_tika\Exception\TikaException;
use Drupal\document_loader_tika\TikaClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts file content through an Apache Tika server.
 *
 * The plugin sends the file to the Tika server and returns the text or
 * HTML in the response as the loader output. Tika does the parsing for
 * every supported document type, so there is no per-type logic here.
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
   * The Tika client.
   */
  protected TikaClientInterface $client;

  /**
   * The file system, resolving stream URIs to paths.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The output factory.
   */
  protected DocumentLoaderTypeFactory $typeFactory;

  /**
   * The config factory, for the availability check.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    mixed $plugin_definition,
  ): static {
    // The parent builds the instance, so this class never depends on the
    // parent constructor signature.
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get(TikaClientInterface::class);
    $instance->fileSystem = $container->get(FileSystemInterface::class);
    $instance->typeFactory = $container->get('document_loader.type_factory');
    $instance->configFactory = $container->get(ConfigFactoryInterface::class);

    return $instance;
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
    $uri = $input instanceof FileInput ? $input->getFileUri() : $input->getContent();
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
