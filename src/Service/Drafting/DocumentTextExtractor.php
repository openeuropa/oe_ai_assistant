<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\document_loader\DocumentLoaderType\Input\MarkdownInput;
use Drupal\document_loader\DocumentLoaderType\Input\PdfInput;
use Drupal\document_loader\DocumentLoaderType\Input\TextInput;
use Drupal\document_loader\DocumentLoaderType\Input\WordInput;
use Drupal\document_loader\Service\DocumentLoaderManager;
use Drupal\file\FileInterface;
use Drupal\oe_ai_assistant\Exception\DocumentExtractionException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Text extractor built on the document_loader framework.
 */
final class DocumentTextExtractor implements DocumentTextExtractorInterface {

  /**
   * Accepted extensions mapped to their document_loader type and input class.
   *
   * @var array
   */
  private const array TYPES = [
    'txt' => ['text', TextInput::class],
    'md' => ['markdown', MarkdownInput::class],
    'docx' => ['word', WordInput::class],
    'doc' => ['word', WordInput::class],
    'pdf' => ['pdf', PdfInput::class],
  ];

  /**
   * Word bookmark markers Tika leaves in plain text, such as [bookmark: _Toc0].
   */
  private const string BOOKMARK_PATTERN = '/\[bookmark: [^\]]*\]/';

  public function __construct(
    #[Autowire(service: 'document_loader.manager')]
    private readonly DocumentLoaderManager $manager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function extract(FileInterface $file): string {
    $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
    if (!isset(self::TYPES[$extension])) {
      throw new DocumentExtractionException(sprintf('Unsupported document extension "%s".', $extension));
    }
    [$type, $inputClass] = self::TYPES[$extension];

    try {
      // File inputs grant neutral access, so the current user (anonymous on
      // cron) never blocks the load.
      $result = $this->manager->loadFromInput(
        'document_loader_type:' . $type,
        new $inputClass((string) $file->getFileUri()),
        $this->currentUser,
        'text',
      );
    }
    catch (\Throwable $e) {
      throw new DocumentExtractionException('Text extraction failed: ' . $e->getMessage(), 0, $e);
    }

    $content = trim((string) preg_replace(self::BOOKMARK_PATTERN, '', $result->content));
    if ($content === '') {
      throw new DocumentExtractionException('Text extraction returned no text.');
    }

    return $content;
  }

}
