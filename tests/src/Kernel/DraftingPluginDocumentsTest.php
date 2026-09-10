<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\FileInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Controller\PluginController;
use Drupal\oe_ai_assistant\Exception\ActionException;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for DraftingPlugin document actions.
 */
#[Group('oe_ai_assistant')]
class DraftingPluginDocumentsTest extends AiEditorialSessionKernelTestBase {

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
  }

  /**
   * Tests adding, listing, and removing context documents.
   */
  public function testDocumentActions(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    $contents = 'Context document contents.';
    $addRequest = $this->createUploadRequest((string) $session->id(), 'context', 'brief.txt', $contents);

    $addResponse = $plugin->executeAction('add-document', $addRequest);

    $this->assertArrayHasKey('document', $addResponse);
    $this->assertSame('brief.txt', $addResponse['document']['title']);
    $this->assertSame('txt', $addResponse['document']['meta']['type']);
    $this->assertNotEmpty($addResponse['document']['meta']['size']);

    $documentId = $addResponse['document']['id'];
    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->load($documentId);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $this->assertSame('ai_context_document', $media->bundle());
    $this->assertFalse($media->isPublished());

    $file = $media->get('oe_ai_context_document')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertStringStartsWith('private://ai-context-documents/', $file->getFileUri());
    $this->assertSame(strlen($contents), (int) $file->getSize());

    $sessionStorage = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    $reloadedSession = $sessionStorage->load($session->id());
    $this->assertSame($documentId, (string) $reloadedSession->get('context_documents')->target_id);

    $listRequest = Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
    ], JSON_THROW_ON_ERROR));
    $listResponse = $plugin->executeAction('list-documents', $listRequest);

    $this->assertSame([$addResponse['document']], $listResponse['documents']);
    $this->assertArrayNotHasKey('url', $listResponse['documents'][0]);

    $removeRequest = Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $documentId,
    ], JSON_THROW_ON_ERROR));
    $removeResponse = $plugin->executeAction('remove-document', $removeRequest);

    $this->assertSame(['status' => 'ok'], $removeResponse);
    $sessionStorage->resetCache([$session->id()]);
    $reloadedSession = $sessionStorage->load($session->id());
    $this->assertTrue($reloadedSession->get('context_documents')->isEmpty());
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('media')->load($documentId));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('file')->load($file->id()));
  }

  /**
   * Tests document actions through the plugin controller.
   */
  public function testDocumentActionsThroughController(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $controller = $this->createPluginController();

    $addRequest = $this->createUploadRequest((string) $session->id(), 'context', 'controller-brief.txt', 'Context document contents.');

    $addResponse = $controller->dispatch('drafting', 'add-document', $addRequest);
    $this->assertInstanceOf(JsonResponse::class, $addResponse);
    $this->assertSame(200, $addResponse->getStatusCode());
    $addPayload = json_decode($addResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame('controller-brief.txt', $addPayload['document']['title']);
    $this->assertSame('txt', $addPayload['document']['meta']['type']);
    $documentId = $addPayload['document']['id'];

    $listRequest = Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
    ], JSON_THROW_ON_ERROR));
    $listResponse = $controller->dispatch('drafting', 'list-documents', $listRequest);
    $this->assertInstanceOf(JsonResponse::class, $listResponse);
    $this->assertSame(200, $listResponse->getStatusCode());
    $listPayload = json_decode($listResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame([$addPayload['document']], $listPayload['documents']);

    $removeRequest = Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $documentId,
    ], JSON_THROW_ON_ERROR));
    $removeResponse = $controller->dispatch('drafting', 'remove-document', $removeRequest);
    $this->assertInstanceOf(JsonResponse::class, $removeResponse);
    $this->assertSame(200, $removeResponse->getStatusCode());
    $removePayload = json_decode($removeResponse->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(['status' => 'ok'], $removePayload);
    $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->resetCache([$session->id()]);
    $reloadedSession = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->load($session->id());
    $this->assertTrue($reloadedSession->get('context_documents')->isEmpty());
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('media')->load($documentId));
  }

  /**
   * Tests the controller rejects non-string add-document fields via the schema.
   */
  #[DataProvider('nonStringAddDocumentFieldProvider')]
  public function testAddDocumentRejectsNonStringFieldsThroughController(string $field): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $controller = $this->createPluginController();

    // Replace one scalar field with an array, as a client would by sending
    // the parameter with a bracket suffix.
    $fields = [
      'sessionId' => (string) $session->id(),
      'category' => 'context',
      'filename' => 'non-string-field.txt',
    ];
    $fields[$field] = [$fields[$field]];

    $this->container->get('file.input_stream_file_writer')->setContents('Context document contents.');
    $request = Request::create('?' . http_build_query($fields), 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/octet-stream',
    ], 'Context document contents.');

    $response = $controller->dispatch('drafting', 'add-document', $request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(400, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('bad_request', $payload['code']);
    $this->assertStringContainsString($field . ':', $payload['message']);

    $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->resetCache([$session->id()]);
    $reloadedSession = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->load($session->id());
    $this->assertTrue($reloadedSession->get('context_documents')->isEmpty());
  }

  /**
   * Provides the add-document scalar fields that must be strings.
   *
   * @return array<string, array{0: string}>
   *   Test cases keyed by field name.
   */
  public static function nonStringAddDocumentFieldProvider(): array {
    return [
      'sessionId' => ['sessionId'],
      'category' => ['category'],
    ];
  }

  /**
   * Tests the document title uses the sanitized file name.
   *
   * Core renames files with insecure double extensions (brief.php.txt
   * becomes brief.php_.txt). The media label, which is echoed back to the
   * client and embedded in the page bootstrap, must reflect the stored file
   * name rather than the raw client-supplied one.
   */
  public function testDocumentTitleUsesSanitizedFilename(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    $request = $this->createUploadRequest((string) $session->id(), 'context', 'brief.php.txt', 'Context document contents.');
    $response = $plugin->executeAction('add-document', $request);

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->load($response['document']['id']);
    $this->assertInstanceOf(MediaInterface::class, $media);
    $storedFilename = $media->get('oe_ai_context_document')->entity->getFilename();

    $this->assertSame('brief.php_.txt', $storedFilename);
    $this->assertSame($storedFilename, $media->label());
    $this->assertSame($storedFilename, $response['document']['title']);
  }

  /**
   * Tests document uploads reject files larger than the configured field limit.
   */
  public function testDocumentUploadRejectsFileLargerThanConfiguredLimit(): void {
    $fieldConfig = $this->container->get('entity_type.manager')
      ->getStorage('field_config')
      ->load('media.ai_context_document.oe_ai_context_document');
    $this->assertInstanceOf(FieldConfigInterface::class, $fieldConfig);
    $fieldConfig->setSetting('max_filesize', '2 KB');
    $fieldConfig->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    $request = $this->createUploadRequest((string) $session->id(), 'context', 'oversized-document.txt', str_repeat('a', 3000));

    try {
      $plugin->executeAction('add-document', $request);
      $this->fail('The add-document action did not reject an oversized file.');
    }
    catch (ActionException $e) {
      $this->assertSame('invalid_request', $e->errorCode);
      $this->assertSame(400, $e->statusCode);
      $this->assertStringContainsString('exceeding the maximum file size', $e->getMessage());
    }

    $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->resetCache([$session->id()]);
    $reloadedSession = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->load($session->id());
    $this->assertTrue($reloadedSession->get('context_documents')->isEmpty());
  }

  /**
   * Tests document uploads reject unsupported extensions.
   */
  public function testDocumentUploadRejectsUnsupportedExtension(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    $request = $this->createUploadRequest((string) $session->id(), 'context', 'unsupported-extension.exe', random_bytes(128));

    try {
      $plugin->executeAction('add-document', $request);
      $this->fail('The add-document action did not reject an unsupported extension.');
    }
    catch (ActionException $e) {
      $this->assertSame('invalid_request', $e->errorCode);
      $this->assertSame(400, $e->statusCode);
      $this->assertStringContainsString('Only files with the following extensions are allowed:', $e->getMessage());
    }

    $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->resetCache([$session->id()]);
    $reloadedSession = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->load($session->id());
    $this->assertTrue($reloadedSession->get('context_documents')->isEmpty());
  }

  /**
   * Tests document actions deny users without session access.
   */
  #[DataProvider('documentActionAccessProvider')]
  public function testDocumentActionsDenyUsersWithoutSessionAccess(string $action): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $this->container->get('current_user')->setAccount($this->createUser());
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    try {
      $plugin->executeAction($action, $this->createDocumentActionRequest($action, (string) $session->id()));
      $this->fail(sprintf('The %s action did not deny access.', $action));
    }
    catch (ActionException $e) {
      $this->assertSame('forbidden', $e->errorCode);
      $this->assertSame(403, $e->statusCode);
      $this->assertSame('Access to the editorial session is denied.', $e->getMessage());
    }
  }

  /**
   * Tests unsupported document categories are rejected.
   */
  #[DataProvider('documentActionAccessProvider')]
  public function testUnsupportedDocumentCategoryIsRejected(string $action): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    try {
      $plugin->executeAction($action, $this->createDocumentActionRequest($action, (string) $session->id(), 'unsupported'));
      $this->fail(sprintf('The %s action did not reject an unsupported category.', $action));
    }
    catch (ActionException $e) {
      $this->assertSame('invalid_request', $e->errorCode);
      $this->assertSame(400, $e->statusCode);
      $this->assertSame('Unsupported document category "unsupported".', $e->getMessage());
    }
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
    ];
  }

  /**
   * Tests the controller validates the JSON body whatever the Content-Type.
   *
   * The list action reads the JSON body, so that is what must be validated,
   * whatever Content-Type the client sends.
   */
  public function testControllerValidatesJsonBodyRegardlessOfContentType(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $controller = $this->createPluginController();

    // A text/plain Content-Type, a query string that satisfies
    // DraftingListDocumentsRequest, and a body where category is an integer
    // instead of the string the schema demands.
    $query = http_build_query([
      'sessionId' => (string) $session->id(),
      'category' => 'context',
    ]);
    $request = Request::create('?' . $query, 'POST', [], [], [], [
      'CONTENT_TYPE' => 'text/plain',
    ], json_encode([
      'sessionId' => (string) $session->id(),
      'category' => 1,
    ], JSON_THROW_ON_ERROR));

    try {
      $response = $controller->dispatch('drafting', 'list-documents', $request);
    }
    catch (\TypeError $e) {
      // The integer category reached resolveDocumentRepository(), which is
      // typed to accept a string: the body was handed to the action without
      // being validated.
      $this->fail('The JSON body reached the action without validation: ' . $e->getMessage());
    }

    // The body is what the action reads, so the body is what must have been
    // validated: the integer category is rejected before the action runs.
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(400, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('bad_request', $payload['code']);
  }

  /**
   * Tests removing a document from one session keeps it for another.
   *
   * Only the last reference may delete the media and its file.
   */
  public function testRemoveKeepsDocumentSharedWithAnotherSession(): void {
    $owner = $this->createUser();
    $this->container->get('current_user')->setAccount($owner);
    $session = $this->createSession($owner);
    $otherSession = $this->createSession($owner);
    $plugin = $this->container->get(AiAssistantPluginManager::class)
      ->createInstance('drafting');

    // Upload the document through the first session, then reference the
    // resulting media from a second session as well. The API never creates
    // this state itself, but context_documents is a plain multi-value
    // entity reference, so nothing prevents it, and deleteOrphanedBy()
    // already treats it as a case to guard against.
    $addResponse = $plugin->executeAction('add-document', $this->createUploadRequest((string) $session->id(), 'context', 'shared.txt', 'Shared contents.'));
    $documentId = $addResponse['document']['id'];
    $otherSession->get('context_documents')->appendItem(['target_id' => $documentId]);
    $otherSession->save();

    // Remove the document from the first session only.
    $removeRequest = Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $documentId,
    ], JSON_THROW_ON_ERROR));
    $plugin->executeAction('remove-document', $removeRequest);

    // The first session drops its reference.
    $sessionStorage = $this->container->get('entity_type.manager')->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id(), $otherSession->id()]);
    $this->assertTrue($sessionStorage->load($session->id())->get('context_documents')->isEmpty());

    // The second session still references the document, so the media and
    // its file must survive the removal; only the last reference may delete
    // them. Bypass the static cache to read what is actually stored.
    $mediaStorage = $this->container->get('entity_type.manager')->getStorage('media');
    $mediaStorage->resetCache([$documentId]);
    $this->assertInstanceOf(MediaInterface::class, $mediaStorage->load($documentId), 'A document still referenced by another session must not be deleted.');
    $this->assertSame($documentId, (string) $sessionStorage->load($otherSession->id())->get('context_documents')->target_id);
  }

  /**
       * {@inheritdoc}
       */
      public function writeStreamToFile(string $stream = self::DEFAULT_STREAM, int $bytesToRead = self::DEFAULT_BYTES_TO_READ): string {
        throw new UploadException('Input file data could not be read');
      }

    });
    $controller = $this->createPluginController();

    // A well-formed upload request: the failure must come from staging the
    // body, not from validation.
    $query = http_build_query([
      'sessionId' => (string) $session->id(),
      'category' => 'context',
      'filename' => 'brief.txt',
    ]);
    $request = Request::create('?' . $query, 'POST', [], [], [], [
      'CONTENT_TYPE' => 'application/octet-stream',
    ], 'Context document contents.');

    $response = $controller->dispatch('drafting', 'add-document', $request);

    // The repository already maps upload handler failures to an ActionException
    // with the upload_failed code; a staging failure is the same kind of
    // problem and must reach the client in the same JSON error shape rather
    // than as an uncaught exception.
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(500, $response->getStatusCode());
    $payload = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('upload_failed', $payload['code']);
  }

  /**
   * Creates a request for a document action.
   */
  private function createDocumentActionRequest(string $action, string $sessionId, string $category = 'context'): Request {
    if ($action === 'add-document') {
      return $this->createUploadRequest($sessionId, $category, 'access-denied-document.txt', 'Context document contents.');
    }

    $body = [
      'sessionId' => $sessionId,
      'category' => $category,
    ];
    if ($action === 'remove-document') {
      $body['documentId'] = '1';
    }

    return Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Creates a raw-body upload request for the add-document action.
   *
   * The scalar parameters travel in the query string and the file bytes
   * form the whole request body, as the frontend sends them. The bytes are
   * also primed on the writer double, which stands in for php://input.
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
   * Creates the plugin controller with real services.
   */
  private function createPluginController(): PluginController {
    return new PluginController(
      $this->container->get(AiAssistantPluginManager::class),
      $this->container->get(RequestValidator::class),
    );
  }

}
