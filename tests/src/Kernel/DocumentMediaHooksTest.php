<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\oe_ai_assistant\Hook\DocumentMediaHooks;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface as W;
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
    $this->assertArrayHasKey(DocumentMediaHooks::EXTRACT_FIELD, $definitions);

    $first = $this->createFile('one.txt', 'one');
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => 'one.txt',
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $first->id()],
    ]);
    $media->save();
    $workflow = $this->container->get(W::class);
    $this->assertSame(W::STATE_SCHEDULED, $workflow->getState($media));

    // Simulate a finished run, then a save without file change keeps it.
    $media->set(DocumentMediaHooks::EXTRACT_FIELD, 'extract');
    $media->set(DocumentMediaHooks::SUMMARY_FIELD, 'summary');
    $media->set(W::STATE_FIELD, W::STATE_DONE);
    $media->save();
    $media->set('name', 'renamed.txt');
    $media->save();
    $this->assertSame(W::STATE_DONE, $workflow->getState($media));
    $this->assertSame('extract', $media->get(DocumentMediaHooks::EXTRACT_FIELD)->value);

    // Replacing the file resets everything.
    $second = $this->createFile('two.txt', 'two');
    $media->set('oe_ai_context_document', ['target_id' => $second->id()]);
    $media->save();
    $this->assertSame(W::STATE_SCHEDULED, $workflow->getState($media));
    $this->assertTrue($media->get(DocumentMediaHooks::EXTRACT_FIELD)->isEmpty());
    $this->assertTrue($media->get(DocumentMediaHooks::SUMMARY_FIELD)->isEmpty());
  }

}
