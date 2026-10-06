<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\DocumentLoader;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\document_loader\Attribute\DocumentLoader;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderInputInterface;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderOutputInterface;
use Drupal\document_loader\DocumentLoaderType\DocumentLoaderTypeFactory;
use Drupal\document_loader\DocumentLoaderType\Input\FileInput;
use Drupal\document_loader\Exception\DocumentLoaderException;
use Drupal\document_loader\Plugin\DocumentLoaderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads plain-text and Markdown files directly from local storage.
 */
#[DocumentLoader(
  id: 'oe_ai_assistant:flat_file',
  label: new TranslatableMarkup('Local flat file'),
  description: new TranslatableMarkup('Reads plain-text and Markdown files directly from local storage.'),
  document_loader_types: [
    'document_loader_type:text',
    'document_loader_type:markdown',
  ],
  output_types: ['text'],
)]
final class FlatFileLoader extends DocumentLoaderBase {

  /**
   * The file system, resolving stream URIs to paths.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The output factory.
   */
  protected DocumentLoaderTypeFactory $typeFactory;

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
    $instance->fileSystem = $container->get(FileSystemInterface::class);
    $instance->typeFactory = $container->get('document_loader.type_factory');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function load(
    DocumentLoaderInputInterface $input,
    string $output_format = 'text',
  ): DocumentLoaderOutputInterface {
    if (!$input instanceof FileInput) {
      throw new DocumentLoaderException('The local flat-file loader requires a file input.');
    }

    $uri = $input->getFileUri();
    $path = $this->fileSystem->realpath($uri);
    if ($path === FALSE || !is_file($path) || !is_readable($path)) {
      throw new DocumentLoaderException(sprintf('The file %s cannot be read from local storage.', $uri));
    }

    $content = @file_get_contents($path);
    if ($content === FALSE) {
      throw new DocumentLoaderException(sprintf('The file %s could not be read from local storage.', $uri));
    }

    return $this->typeFactory->createOutput('text', $content, [
      'source' => $uri,
      'loader' => $this->getPluginId(),
    ]);
  }

}
