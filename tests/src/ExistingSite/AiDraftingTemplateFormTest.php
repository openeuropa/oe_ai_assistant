<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the AiDraftingTemplate admin UI forms.
 *
 * @group oe_ai_assistant
 */
class AiDraftingTemplateFormTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $admin = $this->createUser(['administer ai_drafting_template']);
    $this->drupalLogin($admin);
  }

  /**
   * Tests that the administer permission grants access to all template routes.
   */
  public function testAllAdminRoutesAreAccessibleWithPermission(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_access_fixture',
      'label' => 'Access fixture',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'A prompt.']],
      'defaults' => [],
    ]);
    $template->save();
    $this->markEntityForCleanup($template);

    $id = $template->id();
    foreach ([
      '/admin/config/ai-editorial/templates',
      '/admin/config/ai-editorial/templates/add',
      '/admin/config/ai-editorial/templates/' . $id,
      '/admin/config/ai-editorial/templates/' . $id . '/delete',
    ] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200, "Expected 200 on $path");
    }
  }

  /**
   * Tests that all template routes are forbidden without the permission.
   */
  public function testAllAdminRoutesAreForbiddenWithoutPermission(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_access_fixture',
      'label' => 'Access fixture',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'A prompt.']],
      'defaults' => [],
    ]);
    $template->save();
    $this->markEntityForCleanup($template);

    $this->drupalLogout();

    $id = $template->id();
    foreach ([
      '/admin/config/ai-editorial/templates',
      '/admin/config/ai-editorial/templates/add',
      '/admin/config/ai-editorial/templates/' . $id,
      '/admin/config/ai-editorial/templates/' . $id . '/delete',
    ] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403, "Expected 403 on $path");
    }
  }

  /**
   * Tests that a valid template can be created through the add form.
   *
   * Fills all fields via the browser and asserts that the saved entity in the
   * database matches the submitted values exactly.
   */
  public function testAddFormCreatesTemplate(): void {
    $this->drupalGet('/admin/config/ai-editorial/templates/add');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Form create test');
    $page->fillField('id', 'test_form_create');
    $page->checkField('status');
    $page->selectFieldOption('content_type', 'oe_news');
    $page->fillField('fields_yaml', "title:\n  prompt: 'Write a headline.'");
    $page->fillField('defaults_yaml', 'langcode: en');
    $page->pressButton('Save');

    $this->assertSession()->pageTextContains('Created AI drafting template Form create test.');

    // Verify that every submitted value was persisted to the database.
    $loaded = AiDraftingTemplate::load('test_form_create');
    $this->assertNotNull($loaded, 'Template was not saved to the database.');
    $this->markEntityForCleanup($loaded);
    $this->assertEquals('Form create test', $loaded->label());
    $this->assertTrue((bool) $loaded->status());
    $this->assertEquals('oe_news', $loaded->getContentType());
    $this->assertEquals(
      ['title' => ['prompt' => 'Write a headline.']],
      $loaded->getFields(),
      'Parsed fields do not match the YAML entered in the form.'
    );
    $this->assertEquals(
      ['langcode' => 'en'],
      $loaded->getDefaults(),
      'Parsed defaults do not match the YAML entered in the form.'
    );
  }

  /**
   * Tests template's edit form.
   */
  public function testEditFormUpdatesTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_form_edit',
      'label' => 'Original label',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Original prompt.']],
      'defaults' => ['langcode' => 'en'],
    ]);
    $template->save();
    $this->markEntityForCleanup($template);

    $this->drupalGet('/admin/config/ai-editorial/templates/test_form_edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('label', 'Original label');
    // The form should pre-populate the YAML fields from the stored entity.
    $this->assertSession()->fieldValueEquals('content_type', 'oe_news');

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Updated label');
    $page->fillField('fields_yaml', "title:\n  prompt: 'Updated prompt.'");
    $page->pressButton('Save');

    $this->assertSession()->pageTextContains('Updated AI drafting template Updated label.');

    // Verify that the label and fields changes are persisted and that untouched
    // properties (content_type, defaults) remain unchanged.
    /** @var \Drupal\oe_ai_assistant\Entity\AiDraftingTemplate $template */
    $template = AiDraftingTemplate::load('test_form_edit');
    $this->assertNotNull($template);
    $this->assertEquals('Updated label', $template->label());
    $this->assertEquals('oe_news', $template->getContentType());
    $this->assertEquals(
      ['title' => ['prompt' => 'Updated prompt.']],
      $template->getFields(),
      'Updated fields were not persisted.'
    );
    $this->assertEquals(
      ['langcode' => 'en'],
      $template->getDefaults(),
      'Defaults changed unexpectedly during edit.'
    );
  }

  /**
   * Tests that invalid YAML in the fields textarea produces a visible error.
   */
  public function testInvalidYamlInFieldsShowsError(): void {
    $this->drupalGet('/admin/config/ai-editorial/templates/add');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Bad YAML');
    $page->fillField('id', 'test_form_create');
    $page->selectFieldOption('content_type', 'oe_news');
    $page->fillField('fields_yaml', 'title: [unclosed bracket');
    $page->fillField('defaults_yaml', '');
    $page->pressButton('Save');

    $this->assertSession()->pageTextContains('invalid YAML');
    $this->assertSession()->elementExists('css', 'textarea[name="fields_yaml"].error');
    $this->assertSession()->elementNotExists('css', 'textarea[name="defaults_yaml"].error');
    // No entity should have been saved.
    $this->assertNull(
      AiDraftingTemplate::load('test_form_create'),
      'Template was saved despite invalid YAML in the fields textarea.'
    );
  }

  /**
   * Tests that a valid YAML referencing a non-existent field shows a error.
   */
  public function testNonExistentFieldShowsValidationError(): void {
    $this->drupalGet('/admin/config/ai-editorial/templates/add');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Bad field');
    $page->fillField('id', 'test_form_create');
    $page->selectFieldOption('content_type', 'oe_news');
    $page->fillField('fields_yaml', "field_does_not_exist:\n  prompt: 'Prompt.'");
    $page->fillField('defaults_yaml', '');
    $page->pressButton('Save');

    $this->assertSession()->pageTextContains(
      "Field 'field_does_not_exist' does not exist on content type 'oe_news'"
    );
    $this->assertSession()->elementExists('css', 'textarea[name="fields_yaml"].error');
    $this->assertSession()->elementNotExists('css', 'textarea[name="defaults_yaml"].error');
    $this->assertNull(
      AiDraftingTemplate::load('test_form_create'),
      'Template was saved despite a non-existent field reference.'
    );
  }

  /**
   * Tests that invalid YAML in the defaults textarea produces a error.
   */
  public function testInvalidYamlInDefaultsShowsError(): void {
    $this->drupalGet('/admin/config/ai-editorial/templates/add');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Bad defaults');
    $page->fillField('id', 'test_form_create');
    $page->selectFieldOption('content_type', 'oe_news');
    $page->fillField('fields_yaml', "title:\n  prompt: 'Headline.'");
    $page->fillField('defaults_yaml', '[unclosed bracket');
    $page->pressButton('Save');

    $this->assertSession()->pageTextContains('invalid YAML');
    $this->assertSession()->elementExists('css', 'textarea[name="defaults_yaml"].error');
    $this->assertSession()->elementNotExists('css', 'textarea[name="fields_yaml"].error');
    $this->assertNull(
      AiDraftingTemplate::load('test_form_create'),
      'Template was saved despite invalid YAML in the defaults textarea.'
    );
  }

  /**
   * Tests that a value referencing a non-existent field highlights defaults.
   */
  public function testNonExistentDefaultFieldHighlightsDefaultsElement(): void {
    $this->drupalGet('/admin/config/ai-editorial/templates/add');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage();
    $page->fillField('label', 'Bad default field');
    $page->fillField('id', 'test_form_create');
    $page->selectFieldOption('content_type', 'oe_news');
    $page->fillField('fields_yaml', "title:\n  prompt: 'Headline.'");
    $page->fillField('defaults_yaml', 'field_does_not_exist: some_value');
    $page->pressButton('Save');

    $this->assertSession()->elementExists('css', 'textarea[name="defaults_yaml"].error');
    $this->assertSession()->elementNotExists('css', 'textarea[name="fields_yaml"].error');
    $this->assertNull(AiDraftingTemplate::load('test_form_create'));
  }

  /**
   * Tests that a template can be deleted through the delete form.
   */
  public function testDeleteFormRemovesTemplate(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'test_form_create',
      'label' => 'To be deleted via UI',
      'status' => TRUE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'A prompt.']],
      'defaults' => [],
    ]);
    $template->save();
    $this->markEntityForCleanup($template);

    // Confirm the entity exists before deletion.
    $this->assertNotNull(AiDraftingTemplate::load('test_form_create'));

    $this->drupalGet('/admin/config/ai-editorial/templates/test_form_create/delete');
    $this->assertSession()->statusCodeEquals(200);

    $this->getSession()->getPage()->pressButton('Delete');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertNull(
      AiDraftingTemplate::load('test_form_create'),
      'Template was not removed from the database after deletion via UI.'
    );
  }

}
