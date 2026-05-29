<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests EntityJsonSchemaComposer schema splitting logic.
 *
 * Uses the oe_news content type from the test fixture to verify
 * that fields are correctly split into main_fields and per-entity
 * reference groups.
 */
#[Group('oe_ai_assistant')]
class GetContentSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'datetime',
    'entity_reference_revisions',
    'paragraphs',
    'file',
    'image',
    'link',
    'taxonomy',
    'inline_entity_form',
    'content_moderation',
    'workflows',
    'options',
    'key',
    'ai',
    'oe_ai_assistant',
    'oe_ai_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
    ]);

    $this->installConfig(['oe_ai_assistant_test']);
  }

  /**
   * Returns the composer service from the container.
   */
  private function composer(): EntityJsonSchemaComposer {
    return $this->container->get(EntityJsonSchemaComposer::class);
  }

  /**
   * Tests that oe_news schema splits into expected groups.
   */
  public function testSplitsOeNewsIntoGroups(): void {
    $groups = $this->composer()->splitSchemaIntoGroups('node', 'oe_news');

    $groupIds = array_column($groups, 'groupId');
    $this->assertContains('main_fields', $groupIds,
      'Should have a main_fields group.');

    // field_content_paragraphs is entity_reference_revisions
    // and should get its own group.
    $this->assertContains('field_content_paragraphs', $groupIds,
      'Paragraphs field should get its own group.');

    // Main fields should contain title and body.
    $mainGroup = $groups[array_search('main_fields', $groupIds)];
    $this->assertContains('title', $mainGroup['fieldNames']);
    $this->assertContains('field_body', $mainGroup['fieldNames']);

    // Main fields should NOT contain the paragraph field.
    $this->assertNotContains('field_content_paragraphs',
      $mainGroup['fieldNames']);

    // Taxonomy/media references should stay in main_fields.
    $this->assertContains('field_news_tags',
      $mainGroup['fieldNames'],
      'Taxonomy reference should stay in main_fields.');

    // Each group should have a schemaSlice with properties.
    foreach ($groups as $group) {
      $this->assertArrayHasKey('schemaSlice', $group);
      $this->assertArrayHasKey('properties',
        $group['schemaSlice']);
    }
  }

  /**
   * Tests that group labels come from Drupal field definitions.
   */
  public function testGroupLabelsFromFieldDefinitions(): void {
    $groups = $this->composer()->splitSchemaIntoGroups('node', 'oe_news');

    $groupIds = array_column($groups, 'groupId');
    $paraIndex = array_search('field_content_paragraphs', $groupIds);
    if ($paraIndex !== FALSE) {
      $this->assertEquals(
        'Content paragraphs',
        $groups[$paraIndex]['label'],
        'Group label should come from the Drupal field label.'
      );
    }
  }

  /**
   * Tests that taxonomy and media references stay in main_fields.
   */
  public function testSimpleReferencesStayInMainFields(): void {
    $groups = $this->composer()->splitSchemaIntoGroups('node', 'oe_news');
    $groupIds = array_column($groups, 'groupId');

    $mainGroup = $groups[array_search('main_fields', $groupIds)];

    $this->assertContains('field_news_tags',
      $mainGroup['fieldNames'],
      'Taxonomy reference should stay in main_fields.');

    $this->assertContains('field_news_image',
      $mainGroup['fieldNames'],
      'Image/file reference should stay in main_fields.');
  }

}
