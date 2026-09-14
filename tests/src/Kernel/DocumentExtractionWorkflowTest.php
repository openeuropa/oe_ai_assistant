<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionProcessorInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the document extraction workflow configuration.
 */
#[Group('oe_ai_assistant')]
class DocumentExtractionWorkflowTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Creates and saves a context document with a text file.
   */
  private function createDocument(): MediaInterface {
    file_put_contents('public://brief.txt', 'payload');
    $file = File::create(['uri' => 'public://brief.txt', 'filename' => 'brief.txt', 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'ai_context_document',
      'name' => 'brief.txt',
      'status' => 0,
      'oe_ai_context_document' => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Reloads a document so the state field forgets its original value.
   */
  private function reload(MediaInterface $media): MediaInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$media->id()]);
    return $storage->load($media->id());
  }

  /**
   * Validates a document and keeps the violations of the state field.
   *
   * @return \Drupal\Core\Entity\EntityConstraintViolationListInterface
   *   The state field violations.
   */
  private function stateViolations(MediaInterface $media) {
    return $media->validate()->getByField(DocumentExtractionProcessorInterface::STATE_FIELD);
  }

  /**
   * Tests that validated saves follow the workflow transitions.
   */
  public function testValidationGuardsTransitions(): void {
    $media = $this->createDocument();
    $this->container->get('current_user')->setAccount($this->createUser());

    // No transition links scheduled and done.
    $media = $this->reload($media);
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_DONE);
    $this->assertCount(1, $this->stateViolations($media));

    // An existing transition passes; staying put always does.
    $media = $this->reload($media);
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_EXTRACTING);
    $this->assertCount(0, $this->stateViolations($media));
    $media = $this->reload($media);
    $this->assertCount(0, $this->stateViolations($media));

    // Unknown states are rejected outright.
    $media = $this->reload($media);
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, 'bogus');
    $this->assertCount(1, $this->stateViolations($media));

    // Code saves without validation and is not constrained.
    $media = $this->reload($media);
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_DONE);
    $media->save();
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_DONE, $this->reload($media)->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value);
  }

}
