<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Exception\TemplateValidationException;

/**
 * Kernel tests for AiDraftingTemplate CRUD and entity-level operations.
 *
 * The oe_ai_assistant_test module provides the oe_news content type, the
 * paragraph types, and the news_default / news_with_paragraphs templates,
 * making them available without a running site.
 *
 * @group oe_ai_assistant
 */
class AiDraftingTemplateCrudTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
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
    'serialization',
    'image',
    'link',
    'taxonomy',
    // Contrib.
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    // This project.
    'oe_ai_assistant',
    'oe_ai_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['oe_ai_assistant_test']);
  }

  /**
   * {@inheritdoc}
   */
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
   * Tests creating a template and loading by ID with all properties intact.
   */
  public function testCreateAndLoadTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_news_crud',
      'label' => 'Test news CRUD',
      'description' => 'CRUD test template',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Write a headline.']],
      'defaults' => [
        'langcode' => [
          'default_value' => [['value' => 'en']],
        ],
      ],
    ]);
    $template->save();

    $loaded = AiDraftingTemplate::load('test_news_crud');
    $this->assertNotNull($loaded);
    $this->assertEquals('Test news CRUD', $loaded->label());
    $this->assertEquals('oe_news', $loaded->getContentType());
    $this->assertEquals('CRUD test template', $loaded->getDescription());
    $this->assertEquals(['title' => ['prompt' => 'Write a headline.']], $loaded->getFields());
    $this->assertEquals([
      'langcode' => [
        'default_value' => [['value' => 'en']],
      ],
    ], $loaded->getDefaults());
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
   * Tests that installed test templates are returned for the oe_news type.
   */
  public function testGetTemplatesForContentType(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_drafting_template');
    $templates = $storage->loadByProperties(['content_type' => 'oe_news']);

    $this->assertArrayHasKey('news_default', $templates);
    $this->assertArrayHasKey('news_with_paragraphs', $templates);

    foreach ($templates as $template) {
      $this->assertEquals('oe_news', $template->getContentType());
    }
  }

  /**
   * Tests that an empty array is returned when no templates exist for a type.
   */
  public function testGetTemplatesForContentTypeReturnsEmptyForUnknownType(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_drafting_template');
    $templates = $storage->loadByProperties(['content_type' => 'nonexistent_type']);

    $this->assertSame([], $templates);
  }

  /**
   * Tests that the installed news_default template can be loaded by ID.
   */
  public function testLoadTemplateById(): void {
    $template = AiDraftingTemplate::load('news_default');
    $this->assertNotNull($template);
    $this->assertEquals('news_default', $template->id());
    $this->assertEquals('oe_news', $template->getContentType());
  }

  /**
   * Tests that the installed news_default template passes validation.
   */
  public function testInstalledNewsDefaultTemplateIsValid(): void {
    $template = AiDraftingTemplate::load('news_default');
    $result = $template->validate();
    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests that the installed news_with_paragraphs template passes validation.
   */
  public function testInstalledNewsWithParagraphsTemplateIsValid(): void {
    $template = AiDraftingTemplate::load('news_with_paragraphs');
    $result = $template->validate();
    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests that default value schemas use real field definition types.
   */
  public function testDefaultSchemaUsesFieldDefinitionType(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_news_crud',
      'label' => 'Test news CRUD',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => [
        'title' => ['prompt' => 'Write a headline.'],
        'field_body' => [
          'prompt' => 'Write a body.',
          'default_value' => [],
        ],
      ],
      'defaults' => [
        'field_news_image' => [
          'default_value' => [],
        ],
      ],
    ]);
    $template->save();

    $typedConfig = $this->container->get('config.typed');
    $typedConfig->clearCachedDefinitions();
    $definition = $typedConfig->getDefinition('oe_ai_assistant.ai_drafting_template.test_news_crud');

    $this->assertArrayNotHasKey('type', $template->getFields()['field_body']);
    $this->assertArrayNotHasKey('type', $template->getDefaults()['field_news_image']);
    $this->assertSame(
      'field.value.text_with_summary',
      $definition['mapping']['fields']['mapping']['field_body']['mapping']['default_value']['sequence']['type']
    );
    $this->assertSame(
      'field.value.image',
      $definition['mapping']['defaults']['mapping']['field_news_image']['mapping']['default_value']['sequence']['type']
    );
  }

  /**
   * Tests that a template referencing a non-existent content type is invalid.
   */
  public function testNonExistentContentTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_nonexistent', [
      'title' => ['prompt' => 'Headline.'],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches("/Content type 'oe_nonexistent' does not exist/", $result->getErrors());
  }

  /**
   * Tests that a template referencing a non-existent field is invalid.
   */
  public function testNonExistentFieldIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_does_not_exist' => ['prompt' => 'Prompt.'],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Field 'field_does_not_exist' does not exist on content type 'oe_news'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that required content fields must be covered by fields or defaults.
   */
  public function testMissingRequiredFieldIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_teaser' => ['prompt' => 'Teaser.'],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Required field 'title' is missing from template fields or defaults on content type 'oe_news'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that top-level defaults satisfy required node fields.
   */
  public function testRequiredNodeFieldCanBeSatisfiedByDefault(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [['value' => 'Default title']],
      ],
    ]);
    $result = $template->validate();

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests an unknown bundle on an entity_reference_revisions item.
   */
  public function testEntityReferenceRevisionsItemNonExistentBundleIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'items' => [[
          'entity_type' => 'paragraph',
          'bundle' => 'no_such_type',
          'prompt' => 'Nope.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/bundle 'no_such_type' does not exist on entity type 'paragraph'/",
      $result->getErrors()
    );
  }

  /**
   * Tests a missing sub-field on an entity_reference_revisions bundle.
   */
  public function testNonExistentSubFieldOnEntityReferenceRevisionsBundleIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'items' => [[
          'entity_type' => 'paragraph',
          'bundle' => 'text_block',
          'prompt' => 'Text.',
          'fields' => [
            'field_nonexistent_sub' => ['prompt' => 'Nope.'],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Field 'field_nonexistent_sub' does not exist on paragraph 'text_block'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that required referenced fields must be covered by item fields.
   */
  public function testMissingRequiredSubFieldOnReferenceItemIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => ['prompt' => 'Headline.'],
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
          'fields' => [
            'title' => [
              'type' => 'string',
              'default_value' => [['value' => 'Contact']],
            ],
            'field_contact_role' => ['prompt' => 'Role.'],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Required field 'field_contacts.items\[0\] > fields > field_contact_name' is missing from template fields or defaults on content type 'oe_contact'/",
      $result->getErrors()
    );
    $this->assertCount(1, $result->getErrors());
  }

  /**
   * Tests that reference item field defaults satisfy referenced requirements.
   */
  public function testRequiredReferenceFieldCanBeSatisfiedByItemFieldDefault(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => ['prompt' => 'Headline.'],
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
          'fields' => [
            'title' => [
              'type' => 'string',
              'default_value' => [['value' => 'Contact']],
            ],
            'field_contact_name' => [
              'type' => 'string',
              'default_value' => [['value' => 'Jane Doe']],
            ],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests the wrong entity_type on an entity_reference_revisions item.
   */
  public function testEntityReferenceRevisionsItemWrongEntityTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_news',
          'prompt' => 'Nope.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/entity_type 'node' does not match field target type 'paragraph'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that reference items must declare their target entity type.
   */
  public function testEntityReferenceItemMissingEntityTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Item field_contacts.items\\[0\\]: missing entity_type/",
      $result->getErrors()
    );
  }

  /**
   * Tests that reference items must declare their target bundle.
   */
  public function testEntityReferenceItemMissingBundleIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'prompt' => 'Contact.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Item field_contacts.items\\[0\\]: missing bundle/",
      $result->getErrors()
    );
  }

  /**
   * Tests that the template reference type must match the Drupal field type.
   */
  public function testReferenceFieldTypeMismatchIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'paragraph',
          'bundle' => 'text_block',
          'prompt' => 'Paragraph.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Field 'field_content_paragraphs' is a 'entity_reference_revisions' field, not 'entity_reference'/",
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
        ],
        ],
      ],
    ]);
    $result = $template->validate();

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
        ],
        ],
      ],
    ]);
    $result = $template->validate();

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
    $template = $this->buildTemplate(
      'oe_news',
      [
        'title' => [],
      ],
      [
        'field_ghost' => [
          'type' => 'string',
          'default_value' => [['value' => 'value']],
        ],
      ],
    );
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'field_ghost' does not exist on content type 'oe_news'/",
      $result->getErrors()
    );
  }

  /**
   * Tests that default definitions must be mappings.
   */
  public function testDefaultDefinitionMustBeMapping(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'langcode' => 'en',
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'langcode' must be a mapping with a default_value key/",
      $result->getErrors()
    );
  }

  /**
   * Tests that default definitions can omit field type metadata.
   */
  public function testDefaultTypeIsOptional(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => ['default_value' => [['value' => 'Default title']]],
    ]);
    $result = $template->validate();

    $this->assertTrue($result->isValid(), implode(', ', $result->getErrors()));
  }

  /**
   * Tests that default definitions must declare default values.
   */
  public function testDefaultValueIsRequired(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'langcode' => [],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'langcode' is missing default_value/",
      $result->getErrors()
    );
  }

  /**
   * Tests that default values must use Drupal's field item sequence shape.
   */
  public function testDefaultValueMustBeSequence(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'langcode' => [
        'default_value' => 'en',
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'langcode' default_value must be a sequence/",
      $result->getErrors()
    );
  }

  /**
   * Tests that default values are validated by Drupal field constraints.
   */
  public function testDefaultValueMustSatisfyFieldConstraints(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [['value' => str_repeat('a', 256)]],
      ],
    ]);
    $result = $template->validate();

    $this->assertFalse($result->isValid());
    $this->assertErrorMatches(
      "/Default field 'title' default_value is invalid/",
      $result->getErrors()
    );
  }

  /**
   * Tests that __NOW__ in defaults is replaced with the current Unix timestamp.
   */
  public function testResolveDefaultsNowTokenIsReplaced(): void {
    $expectedTime = $this->container->get('datetime.time')->getRequestTime();

    $template = $this->buildTemplate('oe_news', [], [
      'created' => [
        'default_value' => [['value' => '__NOW__']],
      ],
      'langcode' => [
        'default_value' => [['value' => 'en']],
      ],
    ]);
    $resolved = $template->resolveDefaults();

    $this->assertSame($expectedTime, $resolved['created']['default_value'][0]['value']);
    $this->assertSame('en', $resolved['langcode']['default_value'][0]['value']);
  }

  /**
   * Tests that defaults without tokens are returned unchanged.
   */
  public function testResolveDefaultsNoTokensIsPassthrough(): void {
    $defaults = [
      'langcode' => [
        'default_value' => [['value' => 'en']],
      ],
    ];
    $template = $this->buildTemplate('oe_news', [], $defaults);
    $this->assertSame($defaults, $template->resolveDefaults());
  }

  /**
   * Tests that saving a template with an invalid field throws an exception.
   */
  public function testSavingInvalidTemplateThrowsTemplateValidationException(): void {
    try {
      AiDraftingTemplate::create([
        'id' => 'test_news_invalid',
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
      $this->assertEquals('test_news_invalid', $e->getTemplateId());
    }
  }

  /**
   * Builds an unsaved in-memory template for validation testing.
   *
   * @param string $contentType
   *   The content type.
   * @param array $fields
   *   The fields mapping.
   * @param array $defaults
   *   The defaults mapping.
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
   * @param string $pattern
   *   The pattern for matching errors.
   * @param array $errors
   *   The errors matched.
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
