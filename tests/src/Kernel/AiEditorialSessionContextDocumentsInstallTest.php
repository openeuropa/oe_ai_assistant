<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\oe_ai_assistant\Document\ContextDocumentStorage;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the AI editorial session context documents install state.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionContextDocumentsInstallTest extends AiEditorialSessionKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Tests the context documents session field configuration.
   */
  public function testContextDocumentsSessionFieldInstallState(): void {
    $fieldStorage = FieldStorageConfig::loadByName('ai_editorial_session', 'context_documents');

    $this->assertNotNull($fieldStorage);
    $this->assertSame('entity_reference', $fieldStorage->getType());
    $this->assertSame('media', $fieldStorage->getSetting('target_type'));
    $this->assertSame(-1, $fieldStorage->getCardinality());
    $this->assertFalse($fieldStorage->isTranslatable());

    $field = FieldConfig::loadByName('ai_editorial_session', 'content_creation', 'context_documents');

    $this->assertNotNull($field);
    $this->assertSame('entity_reference', $field->getType());
    $this->assertSame('content_creation', $field->getTargetBundle());
    $this->assertFalse($field->isRequired());
    $this->assertFalse($field->isTranslatable());
    $this->assertSame('default:media', $field->getSetting('handler'));
    $this->assertSame([
      'ai_context_document' => 'ai_context_document',
    ], $field->getSetting('handler_settings')['target_bundles']);
    $this->assertFalse($field->getSetting('handler_settings')['auto_create']);

    $formDisplay = EntityFormDisplay::load('ai_editorial_session.content_creation.default');

    $this->assertNotNull($formDisplay);
    $this->assertNull($formDisplay->getComponent('context_documents'));
    $this->assertTrue($formDisplay->get('hidden')['context_documents']);
  }

  /**
   * Tests that context documents reject references to other media bundles.
   */
  public function testContextDocumentsRejectWrongMediaBundle(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $media = $this->createOtherDocumentMedia();

    $session->get(ContextDocumentStorage::SESSION_FIELD)->appendItem([
      'target_id' => $media->id(),
    ]);
    $violations = $session->validate();
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }

    $matchingMessages = array_filter(
      $messages,
      static fn (string $message): bool => str_contains($message, 'cannot be referenced'),
    );

    $this->assertNotEmpty($messages);
    $this->assertNotEmpty($matchingMessages, implode(', ', $messages));
  }

  /**
   * Creates a media entity in a bundle context_documents does not allow.
   */
  private function createOtherDocumentMedia(): Media {
    MediaType::create([
      'id' => 'other_document',
      'label' => 'Other document',
      'source' => 'file',
      'queue_thumbnail_downloads' => FALSE,
      'new_revision' => TRUE,
      'source_configuration' => [
        'source_field' => ContextDocumentStorage::SOURCE_FIELD,
      ],
      'field_map' => [
        'name' => 'name',
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => ContextDocumentStorage::SOURCE_FIELD,
      'entity_type' => 'media',
      'bundle' => 'other_document',
      'label' => 'Document',
      'required' => TRUE,
    ])->save();

    $file = File::create([
      'filename' => 'other-document.txt',
      'uri' => 'public://other-document.txt',
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'other_document',
      'name' => 'Other document',
      'status' => 0,
      ContextDocumentStorage::SOURCE_FIELD => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();

    return $media;
  }

}
