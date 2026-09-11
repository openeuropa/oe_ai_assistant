<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DocumentExtractionWorkflowInterface as W;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the document extraction workflow and its guards.
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
    return $media->validate()->getByField(W::STATE_FIELD);
  }

  /**
   * The workflow service.
   */
  private function workflow(): W {
    return $this->container->get(W::class);
  }

  /**
   * Tests bundle discovery, the initial state and the settled states.
   */
  public function testWorkflowReads(): void {
    $this->assertSame(['ai_context_document'], $this->workflow()->getBundles());

    $media = $this->createDocument();
    $this->assertTrue($this->workflow()->appliesTo($media));
    $this->assertSame(W::STATE_SCHEDULED, $this->workflow()->getState($media));
    $this->assertFalse($media->isPublished());
    $this->assertTrue($this->workflow()->isSettled(W::STATE_DONE));
    $this->assertTrue($this->workflow()->isSettled(W::STATE_ERROR));
    $this->assertFalse($this->workflow()->isSettled(W::STATE_EXTRACTING));
  }

  /**
   * Tests that validated saves follow transitions and the permission.
   */
  public function testValidationGuardsTransitions(): void {
    $media = $this->createDocument();
    $this->container->get('current_user')->setAccount($this->createUser());

    // No transition links scheduled and done.
    $media = $this->reload($media);
    $media->set(W::STATE_FIELD, W::STATE_DONE);
    $this->assertCount(1, $this->stateViolations($media));

    // A transition exists but the editor lacks the permission.
    $media = $this->reload($media);
    $media->set(W::STATE_FIELD, W::STATE_EXTRACTING);
    $this->assertCount(1, $this->stateViolations($media));

    // With the permission the transition passes; staying put always does.
    $this->container->get('current_user')->setAccount($this->createUser([W::TRANSITION_PERMISSION]));
    $media = $this->reload($media);
    $media->set(W::STATE_FIELD, W::STATE_EXTRACTING);
    $this->assertCount(0, $this->stateViolations($media));
    $media = $this->reload($media);
    $this->assertCount(0, $this->stateViolations($media));

    // Unknown states are rejected outright.
    $media = $this->reload($media);
    $media->set(W::STATE_FIELD, 'bogus');
    $this->assertCount(1, $this->stateViolations($media));

    // Code saves without validation and is not constrained.
    $media = $this->reload($media);
    $media->set(W::STATE_FIELD, W::STATE_DONE);
    $media->save();
    $this->assertSame(W::STATE_DONE, $this->workflow()->getState($this->reload($media)));
  }

  /**
   * Builds the media form and returns the options of the state select.
   *
   * A fresh form display is loaded each time: widgets cache their options.
   *
   * @return array<string, string>
   *   The select options keyed by state id.
   */
  private function formOptions(MediaInterface $media): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_form_display');
    $storage->resetCache();
    $display = $storage->load('media.ai_context_document.default');
    $form = [];
    $display->buildForm($this->reload($media), $form, new FormState());

    return array_map('strval', $form[W::STATE_FIELD]['widget']['#options']);
  }

  /**
   * Tests that the form select offers only the reachable states.
   */
  public function testFormOffersReachableStates(): void {
    $media = $this->createDocument();
    $media->set(W::STATE_FIELD, W::STATE_ERROR)->save();

    // Without the permission only the current state remains.
    $this->container->get('current_user')->setAccount($this->createUser());
    $this->assertSame([W::STATE_ERROR => 'Error'], $this->formOptions($media));

    $this->container->get('current_user')->setAccount($this->createUser([W::TRANSITION_PERMISSION]));
    $this->assertSame([
      W::STATE_ERROR => 'Error',
      W::STATE_EXTRACTING => 'Extracting',
      W::STATE_SUMMARIZING => 'Summarizing',
      W::STATE_SCHEDULED => 'Scheduled',
    ], $this->formOptions($media));
  }

  /**
   * Tests that the transition permission exists.
   */
  public function testTransitionPermission(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey(W::TRANSITION_PERMISSION, $permissions);
  }

}
