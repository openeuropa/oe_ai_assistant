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
    $this->tika = $this->mockTika();
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
   * Tests that a run stores the extract and passes through extracted.
   */
  public function testExtractionStepStoresText(): void {
    $this->tika->append(new Response(200, [], 'Full text'));
    $media = $this->createDocument();
    $revisions = $this->countRevisions($media);

    $state = $this->processor()->process($media);

    // The summary step is not implemented yet, so the run ends in error
    // with the extract kept; the next task changes this to done.
    $this->assertSame(W::STATE_ERROR, $state);
    $fresh = $this->reload($media);
    $this->assertSame(W::STATE_ERROR, $this->workflow()->getState($fresh));
    $this->assertSame('Full text', $fresh->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
    $this->assertTrue($fresh->get(DocumentMediaHooks::SUMMARY_FIELD)->isEmpty());
    $this->assertSame($revisions, $this->countRevisions($media));
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
    $media = $this->createDocument();
    $media->set(W::STATE_FIELD, W::STATE_EXTRACTING)->save();

    $this->processor()->process($media, TRUE);

    $this->assertSame('Again', $this->reload($media)->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
  }

  /**
   * Tests that a retry with a stored extract skips the loader.
   */
  public function testRetryWithExtractSkipsLoader(): void {
    $media = $this->createDocument();
    $media->set(DocumentMediaHooks::EXTRACT_FIELD, 'Kept')->set(W::STATE_FIELD, W::STATE_ERROR)->save();

    $this->processor()->process($media);

    $this->assertNull($this->tika->getLastRequest());
    $this->assertSame('Kept', $this->reload($media)->get(DocumentMediaHooks::EXTRACT_FIELD)->value);
  }

  /**
   * Tests that an unavailable lock prevents the claim.
   *
   * The core lock lets the same process re-acquire its own lock, so a
   * refusing backend stands in for the concurrent claimer.
   */
  public function testHeldLockPreventsClaim(): void {
    $media = $this->createDocument();
    $this->container->set('lock', new class() implements LockBackendInterface {

      public function acquire($name, $timeout = 30.0) {
        return FALSE;
      }

      public function lockMayBeAvailable($name) {
        return FALSE;
      }

      public function wait($name, $delay = 30) {
        return TRUE;
      }

      public function release($name) {}

      public function releaseAll($lockId = NULL) {}

      public function getLockId() {
        return 'test';
      }

    });

    $this->assertSame(W::STATE_SCHEDULED, $this->processor()->process($media));
    $this->assertNull($this->tika->getLastRequest());
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
