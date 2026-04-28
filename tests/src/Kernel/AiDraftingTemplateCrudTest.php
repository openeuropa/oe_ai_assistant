<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Exception\TemplateValidationException;
use Drupal\oe_ai_assistant\Service\AiDraftingTemplateManagerInterface;

/**
 * Kernel tests for AiDraftingTemplate CRUD and AiDraftingTemplateManager.
 *
 * The oe_ai_assistant_test module provides the oe_news content type, the
 * paragraph types, and the news_default / news_with_paragraphs templates,
 * making them available without a running site.
 *
 * @group oe_ai_assistant
 */
class AiDraftingTemplateCrudTest extends KernelTestBase {

  protected static $modules = [
    // Drupal core.
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    // Contrib.
    'ai',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    // This project.
    'oe_ai_assistant',
    'oe_ai_assistant_test',
  ];

  private AiDraftingTemplateManagerInterface $manager;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installConfig(['oe_ai_assistant_test']);

    $this->manager = $this->container->get(AiDraftingTemplateManagerInterface::class);
  }

  protected function tearDown(): void {
    foreach (['test_news_crud', 'test_paragraphs_crud', 'test_contacts_crud'] as $id) {
      $template = AiDraftingTemplate::load($id);
      if ($template) {
        $template->delete();
      }
    }
    parent::tearDown();
  }

  /**
   * Tests creating a template and loading it back by ID with all properties intact.
   */
  public function testCreateAndLoadTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_news_crud',
      'label' => 'Test news CRUD',
      'description' => 'CRUD test template',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Write a headline.']],
      'defaults' => ['langcode' => 'en'],
    ]);
    $template->save();

    $loaded = AiDraftingTemplate::load('test_news_crud');
    $this->assertNotNull($loaded);
    $this->assertEquals('Test news CRUD', $loaded->label());
    $this->assertEquals('oe_news', $loaded->getContentType());
    $this->assertEquals('CRUD test template', $loaded->getDescription());
    $this->assertEquals(['title' => ['prompt' => 'Write a headline.']], $loaded->getFields());
    $this->assertEquals(['langcode' => 'en'], $loaded->getDefaults());
  }

  /**
   * Tests that updating a template's label and fields is persisted on reload.
   */
  public function testUpdateTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_news_crud',
      'label' => 'Original label',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Old prompt.']],
      'defaults' => [],
    ]);
    $template->save();

    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $loaded */
    $loaded = AiDraftingTemplate::load('test_news_crud');
    $loaded->set('label', 'Updated label');
    $loaded->set('fields', ['title' => ['prompt' => 'New prompt.']]);
    $loaded->save();

    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $reloaded */
    $reloaded = AiDraftingTemplate::load('test_news_crud');
    $this->assertEquals('Updated label', $reloaded->label());
    $this->assertEquals(['title' => ['prompt' => 'New prompt.']], $reloaded->getFields());
  }

  /**
   * Tests that deleting a template removes it from storage.
   */
  public function testDeleteTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_news_crud',
      'label' => 'To be deleted',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'A prompt.']],
      'defaults' => [],
    ]);
    $template->save();

    AiDraftingTemplate::load('test_news_crud')->delete();

    $this->assertNull(AiDraftingTemplate::load('test_news_crud'));
  }

  /**
   * Tests that both installed test templates are returned for the oe_news type.
   */
  public function testGetTemplatesForContentType(): void {
    $templates = $this->manager->getTemplatesForContentType('oe_news');
    $this->assertArrayHasKey('news_default', $templates);
    $this->assertArrayHasKey('news_with_paragraphs', $templates);

    foreach ($templates as $template) {
      $this->assertEquals('oe_news', $template->getContentType());
    }
  }

  /**
   * Tests that an empty array is returned when no templates exist for the type.
   */
  public function testGetTemplatesForContentTypeReturnsEmptyForUnknownType(): void {
    $templates = $this->manager->getTemplatesForContentType('nonexistent_type');
    $this->assertSame([], $templates);
  }

  /**
   * Tests that loadTemplate returns the correct installed template by ID.
   */
  public function testLoadTemplateReturnsInstalledTemplate(): void {
    $template = $this->manager->loadTemplate('news_default');
    $this->assertEquals('news_default', $template->id());
    $this->assertEquals('oe_news', $template->getContentType());
  }

  /**
   * Tests that loadTemplate throws InvalidArgumentException for a non-existent ID.
   */
  public function testLoadTemplateThrowsForMissingId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->loadTemplate('nonexistent_template');
  }

  /**
   * Tests that the installed news_default template passes Level-2 validation.
   */
  public function testInstalledNewsDefaultTemplateIsValid(): void {
    $template = $this->manager->loadTemplate('news_default');
    $result = $this->manager->validateTemplate($template);
    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests that the installed news_with_paragraphs template passes Level-2 validation.
   */
  public function testInstalledNewsWithParagraphsTemplateIsValid(): void {
    $template = $this->manager->loadTemplate('news_with_paragraphs');
    $result = $this->manager->validateTemplate($template);
    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests that a template referencing a non-existent content type is invalid.
   */
  public function testNonExistentContentTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_nonexistent', [
      'title' => ['prompt' => 'Headline.'],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Content type 'oe_nonexistent' does not exist/", $result->getErrors());
  }

  /**
   * Tests that a template referencing a non-existent field on oe_news is invalid.
   */
  public function testNonExistentFieldIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_does_not_exist' => ['prompt' => 'Prompt.'],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Field 'field_does_not_exist' does not exist on content type 'oe_news'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that a template referencing a non-existent paragraph type is invalid.
   */
  public function testNonExistentParagraphTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [[
          'paragraph_type' => 'no_such_type',
          'prompt' => 'Nope.',
        ]],
      ],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Paragraph type 'no_such_type' does not exist/",
      $result->getErrors()
    );
  }

  /**
   * Tests that a non-existent sub-field on a paragraph type produces a validation error.
   */
  public function testNonExistentSubFieldOnParagraphTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'paragraphs',
        'items' => [[
          'paragraph_type' => 'text_block',
          'prompt' => 'Text.',
          'fields' => [
            'field_nonexistent_sub' => ['prompt' => 'Nope.'],
          ],
        ]],
      ],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Field 'field_nonexistent_sub' does not exist on paragraph 'text_block'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that an entity_reference item with the wrong entity_type is invalid.
   */
  public function testEntityReferenceItemWrongEntityTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'taxonomy_term',
          'bundle' => 'tags',
          'prompt' => 'Contact.',
        ]],
      ],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/entity_type 'taxonomy_term' does not match field target type 'node'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that an entity_reference item with a disallowed bundle is invalid.
   */
  public function testEntityReferenceItemDisallowedBundleIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_news',
          'prompt' => 'Contact.',
        ]],
      ],
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/bundle 'oe_news' is not allowed in field 'field_contacts'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that a default key referencing a non-existent field is invalid.
   */
  public function testNonExistentDefaultFieldIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'field_ghost' => 'value',
    ]);
    $result = $this->manager->validateTemplate($template);

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'field_ghost' does not exist on content type 'oe_news'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that __NOW__ in defaults is replaced with the current Unix timestamp.
   */
  public function testResolveDefaultsNowTokenIsReplaced(): void {
    $expectedTime = $this->container->get('datetime.time')->getRequestTime();

    $resolved = $this->manager->resolveDefaults([
      'created' => '__NOW__',
      'langcode' => 'en',
    ]);

    $this->assertSame($expectedTime, $resolved['created']);
    $this->assertSame('en', $resolved['langcode']);
  }

  /**
   * Tests that defaults without tokens are returned unchanged.
   */
  public function testResolveDefaultsNoTokensIsPassthrough(): void {
    $defaults = ['langcode' => 'en', 'moderation_state' => 'draft'];
    $this->assertSame($defaults, $this->manager->resolveDefaults($defaults));
  }

  /**
   * Tests that saving a template with an invalid field throws TemplateValidationException.
   */
  public function testSavingInvalidTemplateThrowsTemplateValidationException(): void {
    try {
      AiDraftingTemplate::create([
        'id' => 'test_news_crud',
        'label' => 'Bad template',
        'status' => TRUE,
        'content_type' => 'oe_news',
        'fields' => ['field_does_not_exist' => ['prompt' => 'Bad.']],
        'defaults' => [],
      ])->save();
      $this->fail('Expected TemplateValidationException was not thrown.');
    }
    catch (TemplateValidationException $e) {
      $this->assertFalse($e->getResult()->isValid());
      $this->assertNotEmpty($e->getResult()->getErrors());
      $this->assertEquals('test_news_crud', $e->getTemplateId());
    }
  }

  /**
   * Builds an unsaved in-memory template for validation testing.
   */
  private function buildTemplate(string $contentType, array $fields, array $defaults = []): AiDraftingTemplate {
    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    $template = AiDraftingTemplate::create([
      'id' => 'test_validation_' . uniqid(),
      'label' => 'Validation test',
      'status' => TRUE,
      'content_type' => $contentType,
      'fields' => $fields,
      'defaults' => $defaults,
    ]);
    return $template;
  }

  /**
   * Asserts that at least one error matches a regex pattern.
   *
   * @param string[] $errors
   */
  private function assertErrorMatches(string $pattern, array $errors): void {
    foreach ($errors as $error) {
      if (preg_match($pattern, $error)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail(
      "No error matched pattern $pattern. Errors were:\n" . implode("\n", $errors)
    );
  }

}
