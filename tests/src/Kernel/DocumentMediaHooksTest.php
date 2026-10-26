<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the extract field and the presave reset.
 */
#[Group('oe_ai_assistant')]
class DocumentMediaHooksTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Creates a permanent public file with the given contents.
   */
  private function createFile(string $name, string $contents): File {
    file_put_contents('public://' . $name, $contents);
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * Tests that the extract field exists and a replaced file resets the run.
   */
  public function testExtractFieldAndPresaveReset(): void {
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('media', 'ai_context_document');
    $this->assertArrayHasKey(DocumentExtractionProcessorInterface::EXTRACT_FIELD, $definitions);

    $first = $this->createFile('one.txt', 'one');
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => 'one.txt',
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $first->id()],
    ]);
    $media->save();
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value);

    // Simulate a finished run, then a save without file change keeps it.
    $media->set(DocumentExtractionProcessorInterface::EXTRACT_FIELD, 'extract');
    $media->set(DocumentExtractionProcessorInterface::SUMMARY_FIELD, 'summary');
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_DONE);
    $media->save();
    $media->set('name', 'renamed.txt');
    $media->save();
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value);
    $this->assertSame('extract', $media->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->value);

    // Replacing the file resets everything.
    $second = $this->createFile('two.txt', 'two');
    $media->set('oe_ai_context_document', ['target_id' => $second->id()]);
    $media->save();
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value);
    $this->assertTrue($media->get(DocumentExtractionProcessorInterface::EXTRACT_FIELD)->isEmpty());
    $this->assertTrue($media->get(DocumentExtractionProcessorInterface::SUMMARY_FIELD)->isEmpty());
  }

  /**
   * Tests the media listing excludes a context document of another session.
   *
   * Media has no query alter of its own for the base 'view media'/'access
   * media overview' permission, so without this restriction an
   * access-checked query returns every context document regardless of
   * session.
   */
  public function testQueryFilteringExcludesInaccessibleContextDocuments(): void {
    $owner = $this->createUser(['use oe ai assistant']);
    $outsider = $this->createUser();

    $session = $this->createSession($owner);

    $file = $this->createFile('doc.txt', 'contents');
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => 'doc.txt',
      'status' => 1,
      'oe_ai_context_document' => ['target_id' => $file->id()],
      'oe_ai_session' => ['target_id' => $session->id()],
    ]);
    $media->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('media');

    $this->container->get('current_user')->setAccount($owner);
    $owner_ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    $this->assertArrayHasKey($media->id(), $owner_ids);

    $this->container->get('current_user')->setAccount($outsider);
    $outsider_ids = $storage->getQuery()->accessCheck(TRUE)->execute();
    $this->assertArrayNotHasKey($media->id(), $outsider_ids);
  }

}
