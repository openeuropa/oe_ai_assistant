<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Exception\TemplateValidationException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

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
    $this->container->get('config.typed')->clearCachedDefinitions();
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
    $this->assertCount(0, $result, implode(', ', $this->violationMessages($result)));
  }

  /**
   * Tests that the installed news_with_paragraphs template passes validation.
   */
  public function testInstalledNewsWithParagraphsTemplateIsValid(): void {
    $template = AiDraftingTemplate::load('news_with_paragraphs');
    $result = $template->validate();
    $this->assertCount(0, $result, implode(', ', $this->violationMessages($result)));
  }

  /**
   * Tests that calculateDependencies() derives field and bundle dependencies.
   *
   * Field-level and referenced-bundle config dependencies are derived from
   * `fields`/`defaults` rather than hand-maintained, so a template's
   * `dependencies.config` is always complete (see
   * AiDraftingTemplate::calculateDependencies()). This directly exercises
   * that derivation, independent of any shipped fixture.
   */
  public function testCalculateDependenciesDerivesFieldAndBundleDependencies(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => ['prompt' => 'Headline.'],
      'field_teaser' => ['prompt' => 'Teaser.'],
      'field_content_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'items' => [
          [
            'entity_type' => 'paragraph',
            'bundle' => 'text_block',
            'prompt' => 'Text.',
            'fields' => [
              'field_text_body' => ['prompt' => 'Body.'],
            ],
          ],
          [
            'entity_type' => 'paragraph',
            'bundle' => 'quote_block',
            'prompt' => 'Quote.',
            'fields' => [
              'field_quote_text' => ['prompt' => 'Quote text.'],
            ],
          ],
        ],
      ],
    ]);

    $template->calculateDependencies();
    $dependencies = $template->getDependencies()['config'] ?? [];
    sort($dependencies);

    $this->assertSame([
      'field.field.node.oe_news.field_content_paragraphs',
      'field.field.node.oe_news.field_teaser',
      'field.field.paragraph.quote_block.field_quote_text',
      'field.field.paragraph.text_block.field_text_body',
      'node.type.oe_news',
      'paragraphs.paragraphs_type.quote_block',
      'paragraphs.paragraphs_type.text_block',
    ], $dependencies);
  }

  /**
   * Tests that a template referencing a non-existent content type is invalid.
   */
  public function testNonExistentContentTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_nonexistent', [
      'title' => ['prompt' => 'Headline.'],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches("/The 'oe_nonexistent' bundle does not exist on the 'node' entity type./", $this->violationMessages($result));
  }

  /**
   * Tests that content type cannot be blank.
   */
  public function testBlankContentTypeIsInvalid(): void {
    $template = $this->buildTemplate('', [
      'title' => ['prompt' => 'Headline.'],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      '/This value should not be blank./',
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that a template referencing a non-existent field is invalid.
   */
  public function testNonExistentFieldIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_does_not_exist' => [],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/The field 'field_does_not_exist' does not exist on the 'oe_news' bundle of 'node' entity type./",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that an empty field key is invalid.
   */
  public function testEmptyFieldNameIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      '' => ['prompt' => 'Headline.'],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      '/Field name is empty./',
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/Required field 'title' is missing from template fields or defaults on content type 'oe_news'/",
      $this->violationMessages($result)
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

    $this->assertCount(0, $result, implode(', ', $this->violationMessages($result)));
  }

  /**
   * Tests an unknown bundle on an entity_reference_revisions item.
   */
  public function testEntityReferenceRevisionsNonExistentBundleIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_content_paragraphs' => [
        'type' => 'entity_reference_revisions',
        'items' => [
          [
            'entity_type' => 'paragraph',
            'bundle' => 'no_such_type',
          ],
        ],
      ],
    ]);

    $this->container->get('config.typed')->clearCachedDefinitions();
    $result = $template->validate();

    $this->assertSame(
      'no_such_type',
      $template->get('fields')['field_content_paragraphs']['items'][0]['bundle']
    );

    $this->assertErrorMatches(
      "/The 'no_such_type' bundle does not exist on the 'paragraph' entity type./",
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/The field 'field_nonexistent_sub' does not exist on the 'text_block' bundle of 'paragraph' entity type./",
      $this->violationMessages($result)
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
              'prompt' => 'Contact title.',
            ],
            'field_contact_role' => ['prompt' => 'Role.'],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/Required field 'field_contacts.items\[0\] > fields > field_contact_name' is missing from template fields or defaults on content type 'oe_contact'/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that referenced items do not support nested defaults.
   */
  public function testNestedDefaultsOnReferenceItemAreInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => ['prompt' => 'Headline.'],
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
          'fields' => [
            'field_contact_name' => ['prompt' => 'Name.'],
          ],
          'defaults' => [
            'title' => [
              'default_value' => [['value' => 'Contact title']],
            ],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/'defaults' is not a supported key./",
      $this->violationMessages($result)
    );
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

    $this->assertErrorMatches(
      "/entity_type 'node' does not match field target type 'paragraph'/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that reference fields with items must declare their field type.
   */
  public function testReferenceFieldWithItemsMissingTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => ['prompt' => 'Headline.'],
      'field_contacts' => [
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
          'fields' => [
            'title' => ['prompt' => 'Contact title.'],
            'field_contact_name' => ['prompt' => 'Name.'],
          ],
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/Reference field 'field_contacts' must declare type 'entity_reference'/",
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/'entity_type' is a required key./",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that reference item entity_type cannot be null.
   */
  public function testEntityReferenceItemNullEntityTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_contacts' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => NULL,
          'bundle' => 'oe_contact',
          'prompt' => 'Contact.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      '/This value should not be null./',
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/'bundle' is a required key./",
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/Field 'field_content_paragraphs' is a 'entity_reference_revisions' field, not 'entity_reference'/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that an entity_reference item with the wrong entity_type is invalid.
   */
  public function testEntityReferenceItemWrongEntityTypeIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [
      'field_news_tags' => [
        'type' => 'entity_reference',
        'items' => [[
          'entity_type' => 'node',
          'bundle' => 'oe_news',
          'prompt' => 'Tag.',
        ],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/entity_type 'node' does not match field target type 'taxonomy_term'/",
      $this->violationMessages($result)
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

    $this->assertErrorMatches(
      "/bundle 'oe_news' is not allowed in field 'field_contacts'/",
      $this->violationMessages($result)
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
        'field_ghost' => [],
      ],
    );
    $result = $template->validate();

    $this->assertErrorMatches(
      "/The field 'field_ghost' does not exist on the 'oe_news' bundle of 'node' entity type/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that default definitions can omit field type metadata.
   */
  public function testDefaultTypeIsOptional(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [
          ['value' => 'Default title'],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertCount(0, $result, implode(', ', $this->violationMessages($result)));
  }

  /**
   * Tests that default definitions must declare default values.
   */
  public function testDefaultValueIsRequired(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'langcode' => [],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/'default_value' is a required key/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that default values are validated by field constraints.
   */
  public function testDefaultValueMustSatisfyFieldConstraints(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [['value' => str_repeat('a', 256)]],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/Default value for field 'title' is invalid/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that default values that cannot create typed data are invalid.
   */
  public function testDefaultValueCreateExceptionIsInvalid(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [
          ['value' => new \stdClass()],
        ],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/Default value for field 'title' is invalid/",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that empty default values are invalid.
   */
  public function testDefaultValueMustNotBeEmpty(): void {
    $template = $this->buildTemplate('oe_news', [], [
      'title' => [
        'default_value' => [],
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      "/Field 'title' default_value is empty./",
      $this->violationMessages($result)
    );
  }

  /**
   * Tests that field type metadata cannot be an empty string.
   */
  public function testNodeFieldTypeCannotBeEmptyString(): void {
    $template = $this->buildTemplate('oe_news', [
      'title' => [
        'type' => '',
        'prompt' => 'Headline.',
      ],
    ]);
    $result = $template->validate();

    $this->assertErrorMatches(
      '/This value should not be blank./',
      $this->violationMessages($result)
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
      $this->assertGreaterThan(0, count($e->getResult()));
      $this->assertNotEmpty($this->violationMessages($e->getResult()));
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

  /**
   * Returns violation messages as strings.
   *
   * @return string[]
   *   The violation messages.
   */
  private function violationMessages(ConstraintViolationListInterface $violations): array {
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

}
