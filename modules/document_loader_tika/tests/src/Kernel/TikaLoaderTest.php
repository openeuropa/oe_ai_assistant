<?php

declare(strict_types=1);

namespace Drupal\Tests\document_loader_tika\Kernel;

use Drupal\document_loader\DocumentLoaderType\Input\TextInput;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\document_loader\Exception\DocumentLoaderException;
use Drupal\document_loader_tika\Hook\RequirementsHooks;
use Drupal\document_loader_tika\TikaClientInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Tika document loader plugin against a mocked Tika server.
 */
#[Group('document_loader_tika')]
class TikaLoaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'document_loader', 'document_loader_tika'];

  /**
   * The queued responses of the fake Tika server.
   */
  private MockHandler $tika;

  /**
   * The requests the plugin sent, recorded by the Guzzle history middleware.
   *
   * @var array
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['document_loader_tika']);
    $this->tika = new MockHandler();
    $stack = HandlerStack::create($this->tika);
    $stack->push(Middleware::history($this->history));
    // The client is autowired from the core http_client service.
    $this->container->set('http_client', new Client(['handler' => $stack]));
    file_put_contents('public://brief.txt', 'Hello from the file.');
  }

  /**
   * Loads the public file through the document loader manager.
   */
  private function load(string $format = 'text'): string {
    $result = $this->container->get('document_loader.manager')->loadFromInput(
      'document_loader_type:text',
      new TextInput('public://brief.txt'),
      $this->container->get('current_user'),
      $format,
    );
    return $result->content;
  }

  /**
   * Tests that plain text is requested and returned as is.
   */
  public function testTextExtraction(): void {
    $this->tika->append(new Response(200, [], "Extracted text\n"));

    $this->assertSame('Extracted text', $this->load());

    $request = $this->history[0]['request'];
    $this->assertSame('PUT', $request->getMethod());
    $this->assertSame('http://tika:9998/tika', (string) $request->getUri());
    $this->assertSame('text/plain', $request->getHeaderLine('Accept'));
    $this->assertSame('Hello from the file.', (string) $request->getBody());
  }

  /**
   * Tests that the html format asks Tika for XHTML.
   */
  public function testHtmlExtraction(): void {
    $this->tika->append(new Response(200, [], '<html><body><p>Hi</p></body></html>'));

    $this->assertStringContainsString('<p>Hi</p>', $this->load('html'));
    $this->assertSame('text/html', $this->history[0]['request']->getHeaderLine('Accept'));
  }

  /**
   * Tests that server errors and empty bodies throw.
   */
  public function testFailuresThrow(): void {
    $this->tika->append(new Response(500, [], 'boom'));
    try {
      $this->load();
      $this->fail('A 500 response must throw.');
    }
    catch (DocumentLoaderException $e) {
      $this->assertStringContainsString('500', $e->getMessage());
    }

    $this->tika->append(new Response(200, [], "  \n"));
    $this->expectException(DocumentLoaderException::class);
    $this->load();
  }

  /**
   * Tests that an unreachable server throws and reports unavailability.
   */
  public function testUnreachableServer(): void {
    $this->tika->append(new ConnectException('refused', new Request('GET', 'http://tika:9998/version')));
    $this->assertFalse($this->container->get(TikaClientInterface::class)->isAvailable());

    $this->tika->append(new ConnectException('refused', new Request('PUT', 'http://tika:9998/tika')));
    $this->expectException(DocumentLoaderException::class);
    $this->load();
  }

  /**
   * Tests that the plugin is available on configuration alone, no ping.
   */
  public function testPluginAvailabilityIsConfigured(): void {
    $plugin = $this->container->get('plugin.manager.document_loader')->createInstance('document_loader_tika:tika');
    $this->assertTrue($plugin->isAvailable());
    $this->assertCount(0, $this->history);

    $this->config('document_loader_tika.settings')->set('url', '')->save();
    $this->assertFalse($plugin->isAvailable());
  }

  /**
   * Tests that the status report reflects the server availability.
   */
  public function testRuntimeRequirements(): void {
    $hooks = $this->container->get(RequirementsHooks::class);

    $this->tika->append(new Response(200, [], 'Apache Tika 3.3.1'));
    $ok = $hooks->runtimeRequirements();
    $this->assertSame(RequirementSeverity::OK, $ok['document_loader_tika']['severity']);
    $this->assertSame('Apache Tika 3.3.1', (string) $ok['document_loader_tika']['value']);

    $this->tika->append(new Response(503, [], ''));
    $down = $hooks->runtimeRequirements();
    $this->assertSame(RequirementSeverity::Error, $down['document_loader_tika']['severity']);
    $this->assertStringContainsString('http://tika:9998', (string) $down['document_loader_tika']['value']);
  }

  /**
   * Tests that the version endpoint is reported when the server answers.
   */
  public function testVersion(): void {
    $this->tika->append(new Response(200, [], "Apache Tika 3.3.1\n"));
    $this->assertSame('Apache Tika 3.3.1', $this->container->get(TikaClientInterface::class)->version());
  }

}
