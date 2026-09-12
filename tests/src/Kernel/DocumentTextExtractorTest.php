<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\Entity\File;
use Drupal\oe_ai_assistant\Exception\DocumentExtractionException;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentTextExtractorInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the document text extractor.
 */
#[Group('oe_ai_assistant')]
class DocumentTextExtractorTest extends AiEditorialSessionKernelTestBase {

  use TikaMockTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['document_loader_tika']);
  }

  /**
   * Creates a public file with the given name.
   */
  private function createFile(string $name): File {
    file_put_contents('public://' . $name, 'payload');
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * Tests that every accepted extension goes through Tika as text.
   */
  #[DataProvider('extensionProvider')]
  public function testExtractsAcceptedExtensions(string $name): void {
    $tika = $this->mockTika();
    $tika->append(new Response(200, [], "Extracted\n"));

    $text = $this->container->get(DocumentTextExtractorInterface::class)->extract($this->createFile($name));

    $this->assertSame('Extracted', $text);
    $this->assertSame('text/plain', $tika->getLastRequest()->getHeaderLine('Accept'));
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
    $this->mockTika()->append(new Response(200, [], "[bookmark: _Toc0]Title\nBody [bookmark: _abc] text"));

    $text = $this->container->get(DocumentTextExtractorInterface::class)->extract($this->createFile('brief.docx'));

    $this->assertSame("Title\nBody  text", $text);
  }

  /**
   * Tests that unsupported extensions fail before any request.
   */
  public function testRejectsUnsupportedExtension(): void {
    $tika = $this->mockTika();
    try {
      $this->container->get(DocumentTextExtractorInterface::class)->extract($this->createFile('brief.zip'));
      $this->fail('Unsupported extensions must throw.');
    }
    catch (DocumentExtractionException $e) {
      $this->assertStringContainsString('zip', $e->getMessage());
    }
    $this->assertNull($tika->getLastRequest());
  }

  /**
   * Tests that loader failures surface as extraction exceptions.
   */
  public function testLoaderFailureIsWrapped(): void {
    $this->mockTika()->append(new Response(503, [], ''));
    $this->expectException(DocumentExtractionException::class);
    $this->container->get(DocumentTextExtractorInterface::class)->extract($this->createFile('brief.pdf'));
  }

}
