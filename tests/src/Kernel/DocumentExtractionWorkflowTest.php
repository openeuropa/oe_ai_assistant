<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Form\FormState;
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
   * Tests the initial state of a new document.
   */
  public function testWorkflowReads(): void {
    $media = $this->createDocument();
    $this->assertSame(DocumentExtractionProcessorInterface::STATE_SCHEDULED, $media->get(DocumentExtractionProcessorInterface::STATE_FIELD)->value);
    $this->assertFalse($media->isPublished());
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

  /**
   * Builds the media form and returns the options of the state select.
   *
   * A fresh form display is loaded each time: widgets cache their options.
   *
   * @return array
   *   The select options keyed by state id.
   */
  private function formOptions(MediaInterface $media): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_form_display');
    $storage->resetCache();
    $display = $storage->load('media.ai_context_document.default');
    $form = [];
    $display->buildForm($this->reload($media), $form, new FormState());

    return array_map('strval', $form[DocumentExtractionProcessorInterface::STATE_FIELD]['widget']['#options']);
  }

  /**
   * Tests that the form select offers only the reachable states.
   */
  public function testFormOffersReachableStates(): void {
    $media = $this->createDocument();
    $media->set(DocumentExtractionProcessorInterface::STATE_FIELD, DocumentExtractionProcessorInterface::STATE_ERROR)->save();
    $this->container->get('current_user')->setAccount($this->createUser());

    $this->assertSame([
      DocumentExtractionProcessorInterface::STATE_ERROR => 'Error',
      DocumentExtractionProcessorInterface::STATE_EXTRACTING => 'Extracting',
      DocumentExtractionProcessorInterface::STATE_SUMMARIZING => 'Summarizing',
      DocumentExtractionProcessorInterface::STATE_SCHEDULED => 'Scheduled',
    ], $this->formOptions($media));
  }

}
