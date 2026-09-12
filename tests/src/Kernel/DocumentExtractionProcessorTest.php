<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface as W;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
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
   * The workflow service.
   */
  private function workflow(): W {
    return $this->container->get(W::class);
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

    $this->assertSame(W::STATE_DONE, $state);
    $fresh = $this->reload($media);
    $this->assertSame(W::STATE_DONE, $this->workflow()->getState($fresh));
    $this->assertSame('Full text', $fresh->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
    $this->assertSame('A brief summary.', $fresh->get(DocumentMediaHooks::SUMMARY_FIELD)->value);
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

    $this->assertSame(W::STATE_ERROR, $state);
    $fresh = $this->reload($media);
    $this->assertSame('Full text', $fresh->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
    $this->assertTrue($fresh->get(DocumentMediaHooks::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Tests that an empty summary counts as a failure.
   */
  public function testEmptySummaryEndsInError(): void {
    $this->tika->append(new Response(200, [], 'Full text'));
    MockAiProvider::enqueue(new MockResponse('   '));
    $media = $this->createDocument();

    $this->assertSame(W::STATE_ERROR, $this->processor()->process($media));
  }

  /**
   * Tests that no configured provider ends in error without a call.
   */
  public function testMissingProviderEndsInError(): void {
    $this->config('ai.settings')->set('default_providers', [])->save();
    $this->tika->append(new Response(200, [], 'Full text'));
    $media = $this->createDocument();

    $this->assertSame(W::STATE_ERROR, $this->processor()->process($media));
    $this->assertCount(0, MockAiProvider::getCallLog());
  }

  /**
   * Tests that a loader failure ends in error with both fields empty.
   */
  public function testLoaderFailureEndsInError(): void {
    $this->tika->append(new Response(500, [], ''));
    $media = $this->createDocument();

    $state = $this->processor()->process($media);

    $this->assertSame(W::STATE_ERROR, $state);
    $this->assertTrue($this->reload($media)->get(DocumentMediaHooks::EXTRACT_FIELD)->isEmpty());
  }

  /**
   * Tests that in-flight and done documents are not claimed.
   */
  public function testInFlightAndDoneAreNoOps(): void {
    foreach ([W::STATE_EXTRACTING, W::STATE_SUMMARIZING, W::STATE_DONE] as $state) {
      $media = $this->createDocument($state . '.txt');
      $media->set(W::STATE_FIELD, $state)->save();

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
    $media->set(W::STATE_FIELD, W::STATE_EXTRACTING)->save();

    $this->assertSame(W::STATE_DONE, $this->processor()->process($media, TRUE));

    $this->assertSame('Again', $this->reload($media)->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
  }

  /**
   * Tests that a retry with a stored extract skips the loader.
   */
  public function testRetryWithExtractSkipsLoader(): void {
    $media = $this->createDocument();
    $media->set(DocumentMediaHooks::EXTRACT_FIELD, 'Kept')->set(W::STATE_FIELD, W::STATE_ERROR)->save();
    MockAiProvider::enqueue(new MockResponse('Summary of kept.'));

    $this->assertSame(W::STATE_DONE, $this->processor()->process($media));

    $this->assertNull($this->tika->getLastRequest());
    $fresh = $this->reload($media);
    $this->assertSame('Kept', $fresh->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
    $this->assertSame('Summary of kept.', $fresh->get(DocumentMediaHooks::SUMMARY_FIELD)->value);
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

    $this->assertSame(W::STATE_SCHEDULED, $this->processor()->process($media));
    $this->assertNull($this->tika->getLastRequest());
  }

  /**
   * Tests that cron processes resting documents and reclaims stale ones.
   */
  public function testCronProcessesPendingDocuments(): void {
    $scheduled = $this->createDocument('scheduled.txt');
    $stale = $this->createDocument('stale.txt');
    $stale->set(W::STATE_FIELD, W::STATE_EXTRACTING)->save();
    $recent = $this->createDocument('recent.txt');
    $recent->set(W::STATE_FIELD, W::STATE_SUMMARIZING)->save();
    $done = $this->createDocument('done.txt');
    $done->set(W::STATE_FIELD, W::STATE_DONE)->save();

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

    $this->assertSame(W::STATE_DONE, $this->workflow()->getState($this->reload($scheduled)));
    $this->assertSame(W::STATE_DONE, $this->workflow()->getState($this->reload($stale)));
    $this->assertSame(W::STATE_SUMMARIZING, $this->workflow()->getState($this->reload($recent)));
    $this->assertSame(W::STATE_DONE, $this->workflow()->getState($this->reload($done)));
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that findPending honours the limit and the stale threshold.
   */
  public function testFindPending(): void {
    $first = $this->createDocument('first.txt');
    $second = $this->createDocument('second.txt');
    $second->set(W::STATE_FIELD, W::STATE_EXTRACTED)->save();
    $stale = $this->createDocument('stale.txt');
    $stale->set(W::STATE_FIELD, W::STATE_SUMMARIZING)->save();
    $this->container->get('database')->update('media_field_data')
      ->fields(['changed' => time() - 3600])
      ->condition('mid', $stale->id())
      ->execute();

    $this->assertSame([
      (int) $first->id() => FALSE,
      (int) $second->id() => FALSE,
      (int) $stale->id() => TRUE,
    ], $this->workflow()->findPending(5, 600));
    $this->assertSame([(int) $first->id() => FALSE], $this->workflow()->findPending(1, 600));
    $this->assertArrayNotHasKey((int) $stale->id(), $this->workflow()->findPending(5, 7200));
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
