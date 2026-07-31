<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the AI editorial session context documents install state.
 */
#[Group('oe_ai_assistant')]
class AiEditorialSessionContextDocumentsInstallTest extends AiEditorialSessionKernelTestBase {

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

}
