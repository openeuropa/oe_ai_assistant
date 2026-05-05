<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\Service\AiDraftingTemplateManager;
use Drupal\oe_ai_assistant\TemplateValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiDraftingTemplateManager validation.
 *
 * Covers content type existence, field existence, paragraph bundle checks,
 * entity reference bundle checks, and defaults field existence — all resolved
 * against mocked Drupal field definitions.
 */
class TemplateManagerValidationTest extends TestCase {

  // ---------------------------------------------------------------------------
  // Level 2: content type existence
  // ---------------------------------------------------------------------------

  /**
   * Tests that a template referencing a non-existent content type is rejected.
   */
  public function testNonExistentContentTypeIsInvalid(): void {
    $manager = $this->buildManager(nodeBundles: []);

    $result = $this->validateLevel2($manager, 'oe_news', []);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Content type 'oe_news' does not exist/", $result);
  }

  /**
   * Tests that a template with a valid content type and no fields passes validation.
   */
  public function testExistingContentTypeWithNoFieldsIsValid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => []],
    );

    $result = $this->validateLevel2($manager, 'oe_news', []);

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  // ---------------------------------------------------------------------------
  // Level 2: field existence
  // ---------------------------------------------------------------------------

  /**
   * Tests that a field name not present on the content type is rejected.
   */
  public function testNonExistentFieldOnContentTypeIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['title' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_ghost' => ['prompt' => 'Does not exist.'],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Field 'field_ghost' does not exist on content type 'oe_news'/", $result);
  }

  /**
   * Tests that a scalar field that exists on the content type passes validation.
   */
  public function testExistingScalarFieldIsValid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['title' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'title' => ['prompt' => 'Write a headline.'],
    ]);

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  // ---------------------------------------------------------------------------
  // Level 2: paragraphs field
  // ---------------------------------------------------------------------------

  /**
   * Tests that using type:paragraphs on a non-paragraph field storage is rejected.
   */
  public function testParagraphsTypeOnWrongFieldStorageIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_body' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_body' => ['type' => 'paragraphs', 'items' => []],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Field 'field_body' is not a paragraph reference field/", $result);
  }

  /**
   * Tests that referencing a paragraph bundle that does not exist is rejected.
   */
  public function testNonExistentParagraphTypeIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_paragraphs' => $this->mockParagraphsField(['text_block'])]],
      paragraphBundles: ['text_block' => ['label' => 'Text block']],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [['paragraph_type' => 'no_such_type', 'prompt' => 'Nope.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Paragraph type 'no_such_type' does not exist/", $result);
  }

  /**
   * Tests that referencing a paragraph bundle not allowed by the field handler is rejected.
   */
  public function testDisallowedParagraphTypeIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_paragraphs' => $this->mockParagraphsField(['text_block'])]],
      paragraphBundles: ['text_block' => ['label' => 'Text block'], 'quote_block' => ['label' => 'Quote']],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [['paragraph_type' => 'quote_block', 'prompt' => 'A quote.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Paragraph type 'quote_block' is not allowed in field 'field_paragraphs' \(allowed: text_block\)/",
      $result
    );
  }

  /**
   * Tests that a sub-field that does not exist on the paragraph type is rejected.
   */
  public function testNonExistentSubFieldOnParagraphTypeIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_paragraphs' => $this->mockParagraphsField(['text_block'])]],
      paragraphBundles: ['text_block' => ['label' => 'Text block']],
      paragraphFields: ['text_block' => ['field_text_body' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [[
          'paragraph_type' => 'text_block',
          'prompt' => 'Text.',
          'fields' => ['field_ghost' => ['prompt' => 'Nope.']],
        ]],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Field 'field_ghost' does not exist on paragraph 'text_block'/", $result);
  }

  /**
   * Tests that a valid paragraphs field with existing allowed bundles and sub-fields passes.
   */
  public function testValidParagraphsFieldIsValid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_paragraphs' => $this->mockParagraphsField(['text_block'])]],
      paragraphBundles: ['text_block' => ['label' => 'Text block']],
      paragraphFields: ['text_block' => ['field_text_body' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [[
          'paragraph_type' => 'text_block',
          'prompt' => 'Text.',
          'fields' => ['field_text_body' => ['prompt' => 'Body text.']],
        ]],
      ],
    ]);

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  // ---------------------------------------------------------------------------
  // Level 2: entity reference field
  // ---------------------------------------------------------------------------

  /**
   * Tests that using type:entity_reference on a non-entity_reference field storage is rejected.
   */
  public function testEntityReferenceTypeOnWrongStorageIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_contacts' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [['entity_type' => 'node', 'bundle' => 'oe_contact', 'prompt' => 'Contact.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Field 'field_contacts' is not an entity_reference field/", $result);
  }

  /**
   * Tests that an entity_reference item referencing a bundle that does not exist is rejected.
   */
  public function testEntityReferenceItemNonExistentBundleIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      // 'oe_contact' is NOT registered in nodeBundles.
      nodeFields: ['oe_news' => ['field_contacts' => $this->mockEntityReferenceField('node', ['oe_contact'])]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [['entity_type' => 'node', 'bundle' => 'oe_contact', 'prompt' => 'Contact.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/bundle 'oe_contact' does not exist on entity type 'node'/",
      $result
    );
  }

  /**
   * Tests that an entity_reference item whose entity_type does not match the
   * field's target type is rejected.
   */
  public function testEntityReferenceItemWrongEntityTypeIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['field_contacts' => $this->mockEntityReferenceField('node', ['oe_contact'])]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [['entity_type' => 'taxonomy_term', 'bundle' => 'tags', 'prompt' => 'Tag.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/entity_type 'taxonomy_term' does not match field target type 'node'/",
      $result
    );
  }

  /**
   * Tests that an entity_reference item whose bundle is not allowed by the
   * field handler is rejected.
   */
  public function testEntityReferenceItemDisallowedBundleIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News'], 'oe_contact' => ['label' => 'Contact'], 'oe_page' => ['label' => 'Page']],
      nodeFields: ['oe_news' => ['field_contacts' => $this->mockEntityReferenceField('node', ['oe_contact'])]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [['entity_type' => 'node', 'bundle' => 'oe_page', 'prompt' => 'Page.']],
      ],
    ]);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/bundle 'oe_page' is not allowed in field 'field_contacts' \(allowed: oe_contact\)/",
      $result
    );
  }

  /**
   * Tests that a valid entity_reference field with an allowed bundle passes.
   */
  public function testValidEntityReferenceFieldIsValid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News'], 'oe_contact' => ['label' => 'Contact']],
      nodeFields: [
        'oe_news' => ['field_contacts' => $this->mockEntityReferenceField('node', ['oe_contact'])],
        'oe_contact' => ['title' => $this->mockScalarField()],
      ],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
          'fields' => ['title' => ['prompt' => 'Name.']],
        ]],
      ],
    ]);

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  // ---------------------------------------------------------------------------
  // Level 2: defaults
  // ---------------------------------------------------------------------------

  /**
   * Tests that a default key referencing a non-existent field is rejected.
   */
  public function testNonExistentDefaultFieldIsInvalid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => ['langcode' => $this->mockScalarField()]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [], ['field_ghost' => 'value']);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'field_ghost' does not exist on content type 'oe_news'/",
      $result
    );
  }

  /**
   * Tests that defaults whose field names all exist on the content type pass validation.
   */
  public function testValidDefaultsIsValid(): void {
    $manager = $this->buildManager(
      nodeBundles: ['oe_news' => ['label' => 'News']],
      nodeFields: ['oe_news' => [
        'langcode' => $this->mockScalarField(),
        'moderation_state' => $this->mockScalarField(),
      ]],
    );

    $result = $this->validateLevel2($manager, 'oe_news', [], [
      'langcode' => 'en',
      'moderation_state' => 'draft',
    ]);

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  // ---------------------------------------------------------------------------
  // Helpers: manager factory
  // ---------------------------------------------------------------------------

  /**
   * Builds a manager with mocked services for Level-2 validation scenarios.
   *
   * @param array<string, array> $nodeBundles
   *   Map of node bundle id => bundle info array.
   * @param array<string, FieldDefinitionInterface[]> $nodeFields
   *   Map of node bundle => [field_name => definition].
   * @param array<string, array> $paragraphBundles
   *   Map of paragraph bundle id => bundle info array.
   * @param array<string, FieldDefinitionInterface[]> $paragraphFields
   *   Map of paragraph bundle => [field_name => definition].
   */
  private function buildManager(
    array $nodeBundles = [],
    array $nodeFields = [],
    array $paragraphBundles = [],
    array $paragraphFields = [],
  ): AiDraftingTemplateManager {
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')
      ->willReturnCallback(fn(string $entityType) => match ($entityType) {
        'node' => $nodeBundles,
        'paragraph' => $paragraphBundles,
        default => [],
      });

    $fieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fieldManager->method('getFieldDefinitions')
      ->willReturnCallback(fn(string $entityType, string $bundle) => match ($entityType) {
        'node' => $nodeFields[$bundle] ?? [],
        'paragraph' => $paragraphFields[$bundle] ?? [],
        default => [],
      });

    return new AiDraftingTemplateManager(
      $this->createMock(EntityTypeManagerInterface::class),
      $fieldManager,
      $bundleInfo,
      $this->createMock(TimeInterface::class),
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers: field definition mocks
  // ---------------------------------------------------------------------------

  /**
   * Returns a mock for a plain scalar field (string, text, etc.).
   */
  private function mockScalarField(): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getType')->willReturn('string');

    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    return $field;
  }

  /**
   * Returns a mock for an entity_reference_revisions field targeting paragraphs.
   *
   * @param string[] $allowedBundles
   */
  private function mockParagraphsField(array $allowedBundles): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getType')->willReturn('entity_reference_revisions');
    $storage->method('getSetting')->with('target_type')->willReturn('paragraph');

    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    $field->method('getSetting')->with('handler_settings')->willReturn([
      'target_bundles' => array_combine($allowedBundles, $allowedBundles),
    ]);
    return $field;
  }

  /**
   * Returns a mock for an entity_reference field targeting a given entity type.
   *
   * @param string[] $allowedBundles
   */
  private function mockEntityReferenceField(string $targetType, array $allowedBundles): FieldDefinitionInterface {
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('getType')->willReturn('entity_reference');
    $storage->method('getSetting')->with('target_type')->willReturn($targetType);

    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getFieldStorageDefinition')->willReturn($storage);
    $field->method('getSetting')->with('handler_settings')->willReturn([
      'target_bundles' => array_combine($allowedBundles, $allowedBundles),
    ]);
    return $field;
  }

  // ---------------------------------------------------------------------------
  // Helpers: validate / assert
  // ---------------------------------------------------------------------------

  /**
   * Runs validation (with content-type and field lookups) against a
   * caller-supplied manager built via buildManager().
   *
   * @param array<string, mixed> $fields
   *   Fields map to pass to the template mock.
   * @param array<string, mixed> $defaults
   *   Defaults map to pass to the template mock.
   */
  private function validateLevel2(
    AiDraftingTemplateManager $manager,
    string $contentType,
    array $fields,
    array $defaults = [],
  ): TemplateValidationResult {
    $template = $this->createMock(AiDraftingTemplateInterface::class);
    $template->method('getContentType')->willReturn($contentType);
    $template->method('getFields')->willReturn($fields);
    $template->method('getDefaults')->willReturn($defaults);
    return $manager->validateTemplate($template);
  }

  /**
   * Fails the test unless at least one error in $result matches $pattern.
   *
   * @param string $pattern
   *   A preg pattern to match against the validation error messages.
   */
  private function assertErrorMatches(string $pattern, TemplateValidationResult $result): void {
    foreach ($result->getErrors() as $error) {
      if (preg_match($pattern, $error)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail("No error matched pattern $pattern. Errors: " . implode(', ', $result->getErrors()));
  }

}
