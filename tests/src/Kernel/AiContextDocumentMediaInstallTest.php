<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the AI context document media install state.
 */
#[Group('oe_ai_assistant')]
class AiContextDocumentMediaInstallTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests the context document media bundle and field configuration.
   */
  public function testContextDocumentMediaInstallState(): void {
    $mediaType = MediaType::load('ai_context_document');

    $this->assertNotNull($mediaType);
    $this->assertSame('AI context document', $mediaType->label());
    $this->assertSame('file', $mediaType->getSource()->getPluginId());
    $this->assertSame(
      'field_media_context_document',
      $mediaType->getSource()->getSourceFieldDefinition($mediaType)->getName(),
    );

    $sourceStorage = FieldStorageConfig::loadByName('media', 'field_media_context_document');
    $this->assertNotNull($sourceStorage);
    $this->assertSame('file', $sourceStorage->getType());
    $this->assertSame('private', $sourceStorage->getSetting('uri_scheme'));
    $this->assertSame(1, $sourceStorage->getCardinality());

    $sourceField = FieldConfig::loadByName('media', 'ai_context_document', 'field_media_context_document');
    $this->assertNotNull($sourceField);
    $this->assertSame('file', $sourceField->getType());
    $this->assertTrue($sourceField->isRequired());

    $summaryStorage = FieldStorageConfig::loadByName('media', 'field_document_summary');
    $this->assertNotNull($summaryStorage);
    $this->assertSame('text_long', $summaryStorage->getType());

    $summaryField = FieldConfig::loadByName('media', 'ai_context_document', 'field_document_summary');
    $this->assertNotNull($summaryField);
    $this->assertSame('text_long', $summaryField->getType());
    $this->assertFalse($summaryField->isRequired());
  }

}
