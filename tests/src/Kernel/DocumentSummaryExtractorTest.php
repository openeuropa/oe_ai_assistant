<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use Drupal\oe_ai_assistant\Exception\DocumentSummaryExtractionException;
use Drupal\oe_ai_assistant\Service\DocumentSummaryExtractorInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel tests for document summary extraction.
 */
#[Group('oe_ai_assistant')]
class DocumentSummaryExtractorTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $privatePath = $this->siteDirectory . '/private';
    mkdir($privatePath);
    $this->setSetting('file_private_path', $privatePath);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules(['oe_ai_assistant_test']);
    $this->installSchema('file', ['file_usage']);
    $this->configureMockProvider();
    MockAiProvider::reset();
  }

  /**
   * Tests direct extraction with the configured mock provider.
   */
  public function testExtractUsesConfiguredProviderAndAttachedFile(): void {
    MockAiProvider::enqueue(new MockResponse('Extracted context summary.'));
    $file = $this->createManagedFile('brief.txt', 'Context document contents.');
    $media = $this->createUnsavedContextDocument($file);

    $summary = $this->container
      ->get(DocumentSummaryExtractorInterface::class)
      ->extract($media);

    $this->assertSame('Extracted context summary.', $summary);
    $this->assertTrue($media->get(ContextDocumentStorage::SUMMARY_FIELD)->isEmpty());

    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $this->assertSame('mock-model', $log[0]['model_id']);
    $this->assertContains('document_summary', $log[0]['tags']);
    $this->assertStringContainsString('briefing summaries', $log[0]['system_prompt']);
    $this->assertSame('user', $log[0]['messages'][0]['role']);
    $this->assertStringContainsString('temporary briefing context', $log[0]['messages'][0]['text']);
    $this->assertSame([
      [
        'filename' => 'brief.txt',
        'mime_type' => 'text/plain',
        'size' => strlen('Context document contents.'),
      ],
    ], $log[0]['messages'][0]['files']);
  }

  /**
   * Tests provider failures clear stale summaries and surface an error.
   */
  public function testProviderErrorLeavesSummaryEmptyAndReportsFailure(): void {
    MockAiProvider::enqueue(new MockResponse(
      error: new \RuntimeException('Provider exploded.'),
    ));
    $file = $this->createManagedFile('failure.txt', 'Context document contents.');
    $media = $this->createUnsavedContextDocument($file, 'Stale summary.');

    try {
      $this->container
        ->get(DocumentSummaryExtractorInterface::class)
        ->extract($media);
      $this->fail('Provider failure did not surface as an extraction exception.');
    }
    catch (DocumentSummaryExtractionException $e) {
      $this->assertSame('The document summary could not be extracted.', $e->getMessage());
      $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
      $this->assertSame('Provider exploded.', $e->getPrevious()->getMessage());
    }

    $this->assertTrue($media->get(ContextDocumentStorage::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Tests media insert hook summarises supported context documents.
   */
  public function testMediaInsertHookSummarisesContextDocument(): void {
    MockAiProvider::enqueue(new MockResponse('Hook summary.'));
    $file = $this->createManagedFile('hook.txt', 'Hook context.');
    $media = $this->createUnsavedContextDocument($file);

    $media->save();

    $this->assertSame('Hook summary.', $media->get(ContextDocumentStorage::SUMMARY_FIELD)->value);
    $this->assertCount(1, MockAiProvider::getCallLog());
  }

  /**
   * Tests media update hook re-extracts only when the source file changes.
   */
  public function testMediaUpdateHookReextractsOnlyWhenSourceFileChanges(): void {
    MockAiProvider::enqueue(new MockResponse('Initial summary.'));
    $firstFile = $this->createManagedFile('first.txt', 'First context.');
    $media = $this->createUnsavedContextDocument($firstFile);
    $media->save();

    $media->setName('Renamed context document');
    $media->save();
    $this->assertSame('Initial summary.', $media->get(ContextDocumentStorage::SUMMARY_FIELD)->value);
    $this->assertCount(1, MockAiProvider::getCallLog());

    MockAiProvider::enqueue(new MockResponse('Replacement summary.'));
    $secondFile = $this->createManagedFile('second.txt', 'Second context.');
    $media->set(ContextDocumentStorage::SOURCE_FIELD, [
      'target_id' => $secondFile->id(),
    ]);
    $media->save();

    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->loadUnchanged($media->id());
    $this->assertInstanceOf(MediaInterface::class, $reloaded);
    $this->assertSame('Replacement summary.', $reloaded->get(ContextDocumentStorage::SUMMARY_FIELD)->value);
    $this->assertCount(2, MockAiProvider::getCallLog());
  }

  /**
   * Tests unsupported media bundles are ignored by the hook.
   */
  public function testUnsupportedMediaBundleIsIgnored(): void {
    MediaType::create([
      'id' => 'unsupported_document',
      'label' => 'Unsupported document',
      'source' => 'file',
      'source_configuration' => [
        'source_field' => ContextDocumentStorage::SOURCE_FIELD,
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => ContextDocumentStorage::SOURCE_FIELD,
      'entity_type' => 'media',
      'bundle' => 'unsupported_document',
      'label' => 'Document',
      'required' => TRUE,
      'settings' => [
        'file_extensions' => 'txt',
      ],
    ])->save();
    $file = $this->createManagedFile('unsupported.txt', 'Unsupported context.');
    $media = Media::create([
      'bundle' => 'unsupported_document',
      'name' => 'Unsupported document',
      'status' => 0,
      ContextDocumentStorage::SOURCE_FIELD => [
        'target_id' => $file->id(),
      ],
    ]);

    $media->save();

    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Tests required document formats are sent with stable MIME types.
   */
  #[DataProvider('supportedFormatProvider')]
  public function testSupportedFormats(string $filename, string $expectedMimeType): void {
    MockAiProvider::enqueue(new MockResponse('Summary for ' . $filename));
    $file = $this->createManagedFile($filename, 'Document contents.');
    $media = $this->createUnsavedContextDocument($file);

    $this->container
      ->get(DocumentSummaryExtractorInterface::class)
      ->extract($media);

    $log = MockAiProvider::getCallLog();
    $this->assertSame($filename, $log[0]['messages'][0]['files'][0]['filename']);
    $this->assertSame($expectedMimeType, $log[0]['messages'][0]['files'][0]['mime_type']);
  }

  /**
   * Provides supported file extensions and expected provider MIME types.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Test cases keyed by extension.
   */
  public static function supportedFormatProvider(): array {
    return [
      'txt' => ['context.txt', 'text/plain'],
      'md' => ['context.md', 'text/markdown'],
      'pdf' => ['context.pdf', 'application/pdf'],
      'docx' => ['context.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
  }

  /**
   * Configures the test AI provider as the default multimodal provider.
   */
  private function configureMockProvider(): void {
    $this->config('ai.settings')
      ->set('default_providers.chat_with_image_vision', [
        'provider_id' => 'mock_ai',
        'model_id' => 'mock-model',
      ])
      ->save();
  }

  /**
   * Creates a managed private file.
   */
  private function createManagedFile(string $filename, string $contents): FileInterface {
    $directory = ContextDocumentStorage::UPLOAD_DIRECTORY;
    $this->container->get('file_system')->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    $file = $this->container->get('file.repository')->writeData(
      $contents,
      $directory . '/' . $filename,
      FileExists::Rename,
    );
    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * Creates an unsaved context document media entity.
   */
  private function createUnsavedContextDocument(FileInterface $file, string $summary = ''): MediaInterface {
    $values = [
      'bundle' => ContextDocumentStorage::MEDIA_BUNDLE,
      'name' => $file->getFilename(),
      'status' => 0,
      ContextDocumentStorage::SOURCE_FIELD => [
        'target_id' => $file->id(),
      ],
    ];
    if ($summary !== '') {
      $values[ContextDocumentStorage::SUMMARY_FIELD] = [
        'value' => $summary,
      ];
    }

    return Media::create($values);
  }

}
