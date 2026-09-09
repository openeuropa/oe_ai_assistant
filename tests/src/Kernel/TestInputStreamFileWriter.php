<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Upload\InputStreamFileWriterInterface;

/**
 * Stages preset bytes instead of reading php://input.
 *
 * Kernel tests cannot feed the PHP input stream, so this double replaces the
 * core writer and stages whatever contents the test primed.
 */
final class TestInputStreamFileWriter implements InputStreamFileWriterInterface {

  /**
   * The bytes to stage on the next write.
   */
  private string $contents = '';

  public function __construct(private readonly FileSystemInterface $fileSystem) {}

  /**
   * Sets the bytes the next write will stage.
   */
  public function setContents(string $contents): void {
    $this->contents = $contents;
  }

  /**
   * {@inheritdoc}
   */
  public function writeStreamToFile(string $stream = self::DEFAULT_STREAM, int $bytesToRead = self::DEFAULT_BYTES_TO_READ): string {
    $path = $this->fileSystem->tempnam('temporary://', 'file');
    file_put_contents($path, $this->contents);

    return $path;
  }

}
