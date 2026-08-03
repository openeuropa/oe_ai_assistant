<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    $source = $this->container->getParameter('site.path') . '/document-source.txt';
    file_put_contents($source, 'Context document contents.');

    $addRequest = Request::create('', 'POST', [
      'sessionId' => $session->id(),
      'category' => 'context',
    ], [], [
      'file' => new UploadedFile(
        $source,
        'brief.txt',
        'text/plain',
        UPLOAD_ERR_OK,
        TRUE,
      ),
    ]);

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

    $file = $media->get('field_media_context_document')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertStringStartsWith('private://ai-context-documents/', $file->getFileUri());

    $sessionStorage = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session');
    $sessionStorage->resetCache([$session->id()]);
    $reloadedSession = $sessionStorage->load($session->id());
    $this->assertSame($documentId, (string) $reloadedSession->get('context_documents')->target_id);

    $listRequest = Request::create('', 'POST', [], [], [], [], json_encode([
      'sessionId' => $session->id(),
      'category' => 'context',
    ], JSON_THROW_ON_ERROR));
    $listResponse = $plugin->executeAction('list-documents', $listRequest);

    $this->assertSame([$addResponse['document']], $listResponse['documents']);
    $this->assertArrayNotHasKey('url', $listResponse['documents'][0]);

    $removeRequest = Request::create('', 'POST', [], [], [], [], json_encode([
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

}
