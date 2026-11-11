<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\oe_ai_assistant\Http\ProviderCallLogger;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what the provider call logger writes to disk.
 *
 * @group oe_ai_assistant
 */
#[Group('oe_ai_assistant')]
class ProviderCallLoggerTest extends AiEditorialSessionKernelTestBase {

  /**
   * Where the private file system lives for this test.
   */
  private string $privatePath;

  /**
   * {@inheritdoc}
   *
   * CoreServiceProvider registers the private stream wrapper only when
   * file_private_path is already set, and this runs before the kernel boots.
   * Set it any later and private:// stays unregistered.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();

    // Point private:// at a real directory. ProviderCallLogger resolves it
    // with realpath(), which returns FALSE for the vfs:// site directory
    // core sets up, and then nothing gets written.
    $this->privatePath = sys_get_temp_dir() . '/' . uniqid('oe-ai-calls-', TRUE);
    mkdir($this->privatePath, 0775, TRUE);
    new Settings(Settings::getAll() + [
      'file_private_path' => $this->privatePath,
      'oe_ai_assistant_log_requests' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (glob($this->privatePath . '/ai-calls/*') ?: [] as $file) {
      unlink($file);
    }
    @rmdir($this->privatePath . '/ai-calls');
    @rmdir($this->privatePath);
    parent::tearDown();
  }

  /**
   * A key in the query string is not written to the file.
   */
  public function testQueryStringSecretIsRedacted(): void {
    $dump = $this->log(new Request(
      'POST',
      'https://provider.example/v1/chat?api-key=sk-live-1234&model=gpt-5',
    ));

    $this->assertStringNotContainsString('sk-live-1234', $dump);
    $this->assertStringContainsString('api-key=[redacted]', $dump);
    // The rest of the query string is written exactly as it was sent.
    $this->assertStringContainsString('model=gpt-5', $dump);
  }

  /**
   * A key in the body is not written to the file, however deeply nested.
   */
  public function testBodySecretIsRedacted(): void {
    $dump = $this->log(new Request(
      'POST',
      'https://provider.example/v1/chat',
      ['Content-Type' => 'application/json'],
      json_encode([
        'model' => 'gpt-5',
        'api_key' => 'sk-live-5678',
        'auth' => ['token' => 'sk-live-9012'],
      ]),
    ));

    $this->assertStringNotContainsString('sk-live-5678', $dump);
    $this->assertStringNotContainsString('sk-live-9012', $dump);
    $this->assertStringContainsString('gpt-5', $dump);
  }

  /**
   * An empty object in the body stays an object.
   *
   * A tool that takes no arguments is declared with an empty
   * "parameters": {}. The file is there to be replayed, and a provider sent
   * "parameters": [] instead can answer differently or reject the call.
   */
  public function testEmptyObjectStaysAnObject(): void {
    $dump = $this->log(new Request(
      'POST',
      'https://provider.example/v1/chat',
      ['Content-Type' => 'application/json'],
      '{"tools":[{"function":{"name":"draft","parameters":{}}}]}',
    ));

    $this->assertStringContainsString('"parameters": {}', $dump);
    $this->assertStringNotContainsString('"parameters": []', $dump);
  }

  /**
   * Passes one request through the middleware and returns what it wrote.
   *
   * @param \GuzzleHttp\Psr7\Request $request
   *   The request to log.
   *
   * @return string
   *   The contents of the file the middleware wrote.
   */
  private function log(Request $request): string {
    // All three cases write to the same directory, and the glob below picks
    // the first file it finds, so clear out what an earlier case wrote.
    $directory = $this->container->get('file_system')->realpath('private://ai-calls');
    foreach (glob(((string) $directory) . '/*.http') ?: [] as $stale) {
      unlink($stale);
    }

    $middleware = $this->container->get(ProviderCallLogger::class)();
    $middleware(static fn (): bool => TRUE)($request, []);

    $files = glob($this->container->get('file_system')->realpath('private://ai-calls') . '/*.http');
    $this->assertNotEmpty($files, 'The middleware wrote a file.');

    return (string) file_get_contents((string) reset($files));
  }

}
