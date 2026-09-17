<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the extraction processor.
 */
#[Group('oe_ai_assistant')]
class DocumentExtractionProcessorTest extends AiEditorialSessionKernelTestBase {

  use TikaMockTrait;

  /**
   * The fake Tika server.
   */
  private MockHandler $tika;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['document_loader_tika']);
    $this->installSchema('file', ['file_usage']);
    // The mock provider plugin only needs the ai module at runtime; the
    // test module's install hook is not run and not needed here. Enabling
    // rebuilds the container, so the Tika mock is installed afterwards.
    $this->enableModules(['oe_ai_assistant_test']);
    $this->tika = $this->mockTika();
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
  }

  /**
   * Creates a scheduled context document media with a text file.
   */
  private function createDocument(string $name = 'brief.txt'): MediaInterface {
    file_put_contents('public://' . $name, 'payload');
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => $name,
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Reloads a media entity from storage.
   */
  private function reload(MediaInterface $media): MediaInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$media->id()]);
    return $storage->load($media->id());
  }

  /**
   * The processor service.
   */
  private function processor(): DocumentExtractionProcessorInterface {
    return $this->container->get(DocumentExtractionProcessorInterface::class);
  }

  /**
   * Reads the stored state of a document.
   */
  private function stateOf(MediaInterface $media): string {
    return (string) $this->reload($media)->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value;
  }

  /**
   * Tests that a full run stores the extract and the summary.
   */
  public function testFullRunEndsInDone(): void {
    $this->tika->append(new Response(200, [], 'Full text'));
    MockAiProvider::enqueue(new MockResponse('A brief summary.'));
    $media = $this->createDocument();
    $revisions = $this->countRevisions($media);

    $state = $this->processor()->process($media);

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $state);
    $fresh = $this->reload($media);
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->stateOf($fresh));
    $this->assertSame('Full text', $fresh->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
    $this->assertSame('A brief summary.', $fresh->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->value);
    $this->assertSame($revisions, $this->countRevisions($media));

    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $this->assertStringContainsString('Full text', $log[0]['messages'][0]['text']);
    $this->assertStringContainsString('summary', strtolower($log[0]['system_prompt']));
  }

  /**
   * Tests that a provider failure keeps the extract and ends in error.
   */
  public function testProviderFailureKeepsExtract(): void {
    $this->tika->append(new Response(200, [], 'Full text'));
    MockAiProvider::enqueue(new MockResponse(error: new \RuntimeException('Provider down.')));
    $media = $this->createDocument();

    $state = $this->processor()->process($media);

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_ERROR, $state);
    $fresh = $this->reload($media);
    $this->assertSame('Full text', $fresh->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
    $this->assertTrue($fresh->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Tests that every accepted extension goes through the loader as text.
   */
  #[DataProvider('extensionProvider')]
  public function testExtractsAcceptedExtensions(string $name): void {
    $this->tika->append(new Response(200, [], "Extracted\n"));
    MockAiProvider::enqueue(new MockResponse('Summary.'));
    $media = $this->createDocument($name);

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->processor()->process($media));
    $this->assertSame('Extracted', $this->reload($media)->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
    $this->assertSame('text/plain', $this->tika->getLastRequest()->getHeaderLine('Accept'));
  }

  /**
   * The accepted source field extensions.
   */
  public static function extensionProvider(): array {
    return [['brief.txt'], ['brief.md'], ['brief.docx'], ['brief.pdf']];
  }

  /**
   * Tests that Word bookmark markers are stripped from the text.
   */
  public function testStripsBookmarkMarkers(): void {
    $this->tika->append(new Response(200, [], "[bookmark: _Toc0]Title\nBody [bookmark: _abc] text"));
    MockAiProvider::enqueue(new MockResponse('Summary.'));
    $media = $this->createDocument('brief.docx');

    $this->processor()->process($media);

    $this->assertSame("Title\nBody  text", $this->reload($media)->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
  }

  /**
   * Tests that an unsupported extension fails before any request.
   */
  public function testUnsupportedExtensionEndsInError(): void {
    $media = $this->createDocument('brief.zip');

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_ERROR, $this->processor()->process($media));
    $this->assertNull($this->tika->getLastRequest());
  }

  /**
   * Tests that a loader failure ends in error with both fields empty.
   */
  public function testLoaderFailureEndsInError(): void {
    $this->tika->append(new Response(500, [], ''));
    $media = $this->createDocument();

    $state = $this->processor()->process($media);

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_ERROR, $state);
    $this->assertTrue($this->reload($media)->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->isEmpty());
  }

  /**
   * Tests that in-flight and done documents are not claimed.
   */
  public function testInFlightAndDoneAreNoOps(): void {
    $states = [
      DocumentExtractionProcessorInterface::STATE_EXTRACTING,
      DocumentExtractionProcessorInterface::STATE_SUMMARIZING,
      DocumentExtractionProcessorInterface::STATE_DONE,
    ];
    foreach ($states as $state) {
      $media = $this->createDocument($state . '.txt');
      $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, $state)->save();

      $this->assertSame($state, $this->processor()->process($media));
      $this->assertNull($this->tika->getLastRequest());
    }
  }

  /**
   * Tests that a stale in-flight document is reclaimed on request.
   */
  public function testReclaimRestartsInFlightDocument(): void {
    $this->tika->append(new Response(200, [], 'Again'));
    MockAiProvider::enqueue(new MockResponse('Summary again.'));
    $media = $this->createDocument();
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_EXTRACTING)->save();

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->processor()->process($media, TRUE));

    $this->assertSame('Again', $this->reload($media)->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
  }

  /**
   * Tests that a retry with a stored extract skips the loader.
   */
  public function testRetryWithExtractSkipsLoader(): void {
    $media = $this->createDocument();
    $media->set(DocumentExtractionProcessorInterface::EXTRACT_FIELD, 'Kept')->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_ERROR)->save();
    MockAiProvider::enqueue(new MockResponse('Summary of kept.'));

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->processor()->process($media));

    $this->assertNull($this->tika->getLastRequest());
    $fresh = $this->reload($media);
    $this->assertSame('Kept', $fresh->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);
    $this->assertSame('Summary of kept.', $fresh->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->value);
  }

  /**
   * Tests that an unavailable lock prevents the claim.
   *
   * The core lock lets the same process re-acquire its own lock, so a
   * refusing backend stands in for the concurrent claimer.
   */
  public function testHeldLockPreventsClaim(): void {
    // The stub must be in place before any save instantiates the processor.
    $this->container->set('lock', new class() implements LockBackendInterface {

      /**
       * {@inheritdoc}
       */
      public function acquire($name, $timeout = 30.0) {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function lockMayBeAvailable($name) {
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function wait($name, $delay = 30) {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function release($name) {}

      /**
       * {@inheritdoc}
       */
      public function releaseAll($lockId = NULL) {}

      /**
       * {@inheritdoc}
       */
      public function getLockId() {
        return 'test';
      }

    });
    $media = $this->createDocument();

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $this->processor()->process($media));
    $this->assertNull($this->tika->getLastRequest());
  }

  /**
   * Tests that cron processes resting documents and reclaims stale ones.
   */
  public function testCronProcessesPendingDocuments(): void {
    $scheduled = $this->createDocument('scheduled.txt');
    $stale = $this->createDocument('stale.txt');
    $stale->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_EXTRACTING)->save();
    $recent = $this->createDocument('recent.txt');
    $recent->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_SUMMARIZING)->save();
    $done = $this->createDocument('done.txt');
    $done->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_DONE)->save();

    // Age the stale document past the threshold.
    $this->container->get('database')->update('media_field_data')
      ->fields(['changed' => time() - 3600])
      ->condition('mid', $stale->id())
      ->execute();

    $this->tika->append(new Response(200, [], 'One'));
    $this->tika->append(new Response(200, [], 'Two'));
    MockAiProvider::enqueue(new MockResponse('Summary one.'));
    MockAiProvider::enqueue(new MockResponse('Summary two.'));

    $this->container->get('module_handler')->invoke('oe_ai_assistant', 'cron');

    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->stateOf($scheduled));
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->stateOf($stale));
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SUMMARIZING, $this->stateOf($recent));
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->stateOf($done));
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Counts the revisions of a media entity.
   */
  private function countRevisions(MediaInterface $media): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('mid', $media->id())
      ->count()
      ->execute();
  }

}
