<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Controller\PluginController;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for DraftingPlugin publishable-asset document actions.
 *
 * The publishable category reuses the same category-generic CRUD as context
 * documents, so this test covers only what the publishable bundle adds: a
 * second bundle resolved through the new enum value, its own private storage,
 * and the known image-extraction gap documented in the OEL-4840 plan.
 */
#[Group('oe_ai_assistant')]
class PublishableAssetDocumentsTest extends AiEditorialSessionKernelTestBase {

  use TikaMockTrait;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
    // Raw upload bodies are read from php://input, which tests cannot feed.
    $container->register('file.input_stream_file_writer', TestInputStreamFileWriter::class)
      ->addArgument(new Reference('file_system'));
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
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['document_loader_tika']);
    // The Tika submodule ships no server URL, so point it at the mock.
    $this->config('document_loader_tika.settings')
      ->set('url', 'http://tika:9998')
      ->save();
    $this->enableModules(['oe_ai_assistant_test']);
    $this->config('ai.settings')
      ->set('default_providers', ['chat' => ['provider_id' => 'mock_ai', 'model_id' => 'mock-model']])
      ->save();
    MockAiProvider::reset();
  }

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
   * Tests the round trip through the controller, exercising the new enum.
   *
   * Dispatching through the controller validates the request against the
   * OpenAPI schema, so this asserts that the widened category enum accepts
   * the publishable value end to end.
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
   * Tests publishable asset actions deny users without session access.
   */
  #[DataProvider('documentActionAccessProvider')]
  public function testPublishableAssetActionsDenyUsersWithoutSessionAccess(string $action): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $this->container->get('current_user')->setAccount($this->createUser());
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    try {
      $plugin->executeAction($action, $this->createPublishableActionRequest($action, (string) $session->id()));
      $this->fail(sprintf('The %s action did not deny access.', $action));
    }
    catch (ActionException $e) {
      $this->assertSame('forbidden', $e->errorCode);
      $this->assertSame(403, $e->statusCode);
      $this->assertSame('Access to the editorial session is denied.', $e->getMessage());
    }
  }

  /**
   * Tests an uploaded image is stored and referenced even without extraction.
   *
   * OEL-4840 scopes publishable assets to storage and CRUD only (decision A
   * in the plan). The extraction pipeline describes documents, not images, so
   * an uploaded image comes to rest in the error state with no description.
   * This documents that known gap: the media and its private file survive and
   * stay referenced; only the extraction state is error. Image description is
   * a follow-up tracked with the placement work (OEL-4844).
   */
  public function testImageAssetIsStoredButRestsInErrorState(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    // A minimal but valid PNG so the file-field image validators accept it.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQAY3Y2wAAAAAElFTkSuQmCC');
    $addRequest = $this->createUploadRequest((string) $session->id(), 'publishable', 'picture.png', $png);
    $added = $plugin->executeAction('add-document', $addRequest);

    // The asset is stored and scheduled for extraction like any document.
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $added['document']['status']);
    $documentId = $added['document']['id'];

    // Running extraction fails on the unsupported image extension, so the
    // asset comes to rest in the error state.
    $extractRequest = $this->createJsonRequest([
      'sessionId' => (string) $session->id(),
      'category' => 'publishable',
      'documentId' => $documentId,
    ]);
    $this->assertSame(
      ['status' => DocumentExtractionProcessorInterface::STATE_ERROR],
      $plugin->executeAction('extract-document', $extractRequest),
    );

    // The media and its private file still exist and stay referenced: only
    // the description is missing.
    $media = $this->container->get('entity_type.manager')->getStorage('media')->load($documentId);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $file = $media->get('oe_ai_publishable_asset')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertStringStartsWith('private://ai-publishable-assets/', $file->getFileUri());
    $this->assertSame(
      DocumentExtractionProcessorInterface::STATE_ERROR,
      $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value,
    );
    $this->assertTrue($media->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Builds a request for a publishable asset action.
   */
  private function createPublishableActionRequest(string $action, string $sessionId): Request {
    if ($action === 'add-document') {
      return $this->createUploadRequest($sessionId, 'publishable', 'access-denied-asset.txt', 'Publishable asset contents.');
    }

    $body = [
      'sessionId' => $sessionId,
      'category' => 'publishable',
    ];
    if ($action === 'remove-document' || $action === 'extract-document') {
      $body['documentId'] = '1';
    }

    return $this->createJsonRequest($body);
  }

  /**
   * Creates a raw-body upload request for the add-document action.
   *
   * The scalar parameters travel in the query string and the file bytes form
   * the whole request body, as the frontend sends them. The bytes are also
   * primed on the writer double, which stands in for php://input.
   */
  private function createUploadRequest(string $sessionId, string $category, string $filename, string $contents): Request {
    $this->container->get('file.input_stream_file_writer')->setContents($contents);
    $query = http_build_query([
      'sessionId' => $sessionId,
      'category' => $category,
      'filename' => $filename,
    ]);

    return Request::create('?' . $query, 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/octet-stream',
    ], $contents);
  }

  /**
   * Builds a JSON action request.
   *
   * @param array<string, string> $body
   *   The request body.
   */
  private function createJsonRequest(array $body): Request {
    return Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Creates the plugin controller with real services.
   */
  private function createPluginController(): PluginController {
    return new PluginController(
      $this->container->get(AiAssistantPluginManager::class),
      $this->container->get(RequestValidator::class),
    );
  }

  /**
   * Provides document action names for access checks.
   *
   * @return array<string, array{0: string}>
   *   Test cases keyed by action name.
   */
  public static function documentActionAccessProvider(): array {
    return [
      'add-document' => ['add-document'],
      'list-documents' => ['list-documents'],
      'remove-document' => ['remove-document'],
      'extract-document' => ['extract-document'],
    ];
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
