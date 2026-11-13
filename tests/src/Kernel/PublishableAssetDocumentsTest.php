<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Kernel tests for the publishable-asset document actions.
 *
 * Add, list and remove run the same code for every category, access
 * checks included, and DraftingPluginDocumentsTest covers them. This class
 * covers what the publishable category brings of its own: its media
 * bundle, its private directory, and its value in the API category enum.
 * It also covers images the extractor cannot read.
 */
#[Group('oe_ai_assistant')]
class PublishableAssetDocumentsTest extends AiEditorialSessionKernelTestBase {

  use DocumentActionsTestTrait;

  /**
   * Tests the add, list and remove round trip for a publishable asset.
   */
  public function testPublishableAssetActions(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    $contents = 'Publishable asset contents.';
    $addRequest = $this->createUploadRequest((string) $session->id(), 'publishable', 'asset.txt', $contents);
    $addResponse = $plugin->executeAction('add-document', $addRequest);

    $this->assertArrayHasKey('document', $addResponse);
    $this->assertSame('asset.txt', $addResponse['document']['title']);
    $this->assertSame('txt', $addResponse['document']['meta']['type']);
    $this->assertNotEmpty($addResponse['document']['meta']['size']);

    $documentId = $addResponse['document']['id'];
    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->load($documentId);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $this->assertSame('ai_publishable_asset', $media->bundle());
    $this->assertFalse($media->isPublished());

    $file = $media->get('oe_ai_publishable_asset')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertStringStartsWith('private://ai-publishable-assets/', $file->getFileUri());
    $this->assertSame(strlen($contents), (int) $file->getSize());
    $this->assertTrue($file->isPermanent());
    $this->assertSame((string) $session->id(), (string) $media->get('oe_ai_session')->target_id);

    $listRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
    ]);
    $listResponse = $plugin->executeAction('list-documents', $listRequest);
    $this->assertSame([$addResponse['document']], $listResponse['documents']);

    $removeRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
      'documentId' => $documentId,
    ]);
    $removeResponse = $plugin->executeAction('remove-document', $removeRequest);

    $this->assertSame(['status' => 'ok'], $removeResponse);
    $this->assertSessionHasNoPublishableAssets($session->id());
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('media')->load($documentId));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('file')->load($file->id()));
  }

  /**
   * Tests the round trip through the controller.
   *
   * The controller runs every request through the OpenAPI schema, so
   * going through it is what proves the schema accepts the publishable
   * category.
   */
  public function testPublishableAssetActionsThroughController(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $controller = $this->createPluginController();

    $addRequest = $this->createUploadRequest((string) $session->id(), 'publishable', 'controller-asset.txt', 'Publishable asset contents.');
    $addResponse = $controller->dispatch('drafting', 'add-document', $addRequest);
    $this->assertInstanceOf(JsonResponse::class, $addResponse);
    $this->assertSame(200, $addResponse->getStatusCode());
    $addPayload = json_decode($addResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame('controller-asset.txt', $addPayload['document']['title']);
    $this->assertSame('txt', $addPayload['document']['meta']['type']);
    $documentId = $addPayload['document']['id'];

    $listRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
    ]);
    $listResponse = $controller->dispatch('drafting', 'list-documents', $listRequest);
    $this->assertInstanceOf(JsonResponse::class, $listResponse);
    $this->assertSame(200, $listResponse->getStatusCode());
    $listPayload = json_decode($listResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([$addPayload['document']], $listPayload['documents']);

    $removeRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
      'documentId' => $documentId,
    ]);
    $removeResponse = $controller->dispatch('drafting', 'remove-document', $removeRequest);
    $this->assertInstanceOf(JsonResponse::class, $removeResponse);
    $this->assertSame(200, $removeResponse->getStatusCode());
    $removePayload = json_decode($removeResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(['status' => 'ok'], $removePayload);
    $this->assertSessionHasNoPublishableAssets($session->id());
  }

  /**
   * Tests that an image is stored like any document but skips extraction.
   *
   * The asset lands in its own private directory and comes to rest in
   * done with no extract and no summary, rather than in error.
   */
  public function testImageAssetSkipsExtraction(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    // The source field accepts a file by extension, not by content, so
    // these bytes stand in for an image.
    $addRequest = $this->createUploadRequest((string) $session->id(), 'publishable', 'picture.png', 'Image bytes.');
    $added = $plugin->executeAction('add-document', $addRequest);

    // The upload schedules the asset for extraction like any document.
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $added['document']['status']);
    $documentId = $added['document']['id'];

    $extractRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
      'documentId' => $documentId,
    ]);
    $this->assertSame(
      ['status' => DocumentExtractionProcessorInterface::STATE_DONE],
      $plugin->executeAction('extract-document', $extractRequest),
    );

    $media = $this->container->get('entity_type.manager')->getStorage('media')->load($documentId);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $file = $media->get('oe_ai_publishable_asset')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertStringStartsWith('private://ai-publishable-assets/', $file->getFileUri());
    $this->assertSame(
      DocumentExtractionProcessorInterface::STATE_DONE,
      $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value,
    );
    $this->assertTrue($media->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->isEmpty());
    $this->assertTrue($media->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Asserts that no publishable asset references the session.
   */
  private function assertSessionHasNoPublishableAssets(string|int $sessionId): void {
    $count = $this->container->get('entity_type.manager')->getStorage('media')->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'ai_publishable_asset')
      ->condition('oe_ai_session', (int) $sessionId)
      ->count()
      ->execute();
    $this->assertSame(0, (int) $count);
  }

}
