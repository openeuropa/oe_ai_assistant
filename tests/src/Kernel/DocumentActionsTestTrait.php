<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\oe_ai_assistant\Controller\PluginController;
use Drupal\oe_ai_assistant\Plugin\AiAssistantPluginManager;
use Drupal\oe_ai_assistant\Service\RequestValidator;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Environment and request builders shared by the document action tests.
 *
 * The actions store uploads as private files and run them through the
 * extraction pipeline, so every test of them needs the same private
 * filesystem, Tika server and chat provider. None of that depends on the
 * document category, so one setup serves the tests of every category.
 */
trait DocumentActionsTestTrait {

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
   * Creates a raw-body upload request for the add-document action.
   *
   * The frontend sends the scalar parameters in the query string and the
   * file bytes as the whole request body, so this does the same. The bytes
   * are also primed on the writer double, which stands in for php://input.
   */
  protected function createUploadRequest(string $sessionId, string $category, string $filename, string $contents): Request {
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
   */
  protected function createJsonRequest(array $body): Request {
    return Request::create('', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Creates the plugin controller with real services.
   */
  protected function createPluginController(): PluginController {
    return new PluginController(
      $this->container->get(AiAssistantPluginManager::class),
      $this->container->get(RequestValidator::class),
    );
  }

}
