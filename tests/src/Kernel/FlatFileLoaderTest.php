<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\document_loader\DocumentLoaderType\Input\MarkdownInput;
use Drupal\document_loader\DocumentLoaderType\Input\TextInput;
use Drupal\document_loader\Exception\DocumentLoaderException;
use Drupal\document_loader\Plugin\DocumentLoaderInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the local flat-file document loader.
 */
#[Group('oe_ai_assistant')]
class FlatFileLoaderTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests that text and Markdown use the configured local loader unchanged.
   */
  public function testLoadsTextAndMarkdownFiles(): void {
    file_put_contents('public://brief.txt', "Plain text\n");
    file_put_contents('public://brief.md', "# Heading\n\nParagraph\n");
    $loader = $this->container->get('document_loader.manager');
    $account = $this->container->get('current_user');

    $text = $loader->loadFromInput(
      'document_loader_type:text',
      new TextInput('public://brief.txt'),
      $account,
    );
    $markdown = $loader->loadFromInput(
      'document_loader_type:markdown',
      new MarkdownInput('public://brief.md'),
      $account,
    );

    $this->assertSame("Plain text\n", $text->content);
    $this->assertSame('oe_ai_assistant:flat_file', $text->metadata['loader']);
    $this->assertSame("# Heading\n\nParagraph\n", $markdown->content);
    $this->assertSame('oe_ai_assistant:flat_file', $markdown->metadata['loader']);
  }

  /**
   * Tests that unreadable local sources fail through the loader exception.
   */
  public function testMissingFileThrows(): void {
    $loader = $this->flatFileLoader();

    $this->expectException(DocumentLoaderException::class);
    $this->expectExceptionMessage('public://missing.txt');
    $loader->load(new TextInput('public://missing.txt'));
  }

  /**
   * Returns the flat-file loader plugin.
   */
  private function flatFileLoader(): DocumentLoaderInterface {
    return $this->container->get('plugin.manager.document_loader')
      ->createInstance('oe_ai_assistant:flat_file');
  }

}
