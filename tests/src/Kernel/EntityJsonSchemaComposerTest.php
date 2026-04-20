<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the leaf-level normalisation produced by EntityJsonSchemaComposer.
 *
 * Task 1 of the OEL-4691 Path A plan: assert that the composer walks a stub
 * `oe_news` node, returns a flat per-field map under `properties`, normalises
 * each leaf via Drupal core's `'json_schema'` format, and excludes the
 * internal base fields that core already filters out via
 * TypedDataInternalPropertiesHelper. Subsequent tasks layer on enrichment,
 * envelope wrapping and recursion.
 */
#[Group('oe_ai_assistant')]
class EntityJsonSchemaComposerTest extends KernelTestBase {

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
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
      'oe_ai_assistant_test',
    ]);
  }

  /**
   * Returns the composer service from the container.
   */
  private function composer(): EntityJsonSchemaComposer {
    return $this->container->get(EntityJsonSchemaComposer::class);
  }

  /**
   * Asserts the composed schema exposes every oe_news field at the top level.
   */
  public function testReturnsArrayWithExpectedTopLevelFields(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    $this->assertIsArray($schema);
    $this->assertArrayHasKey('properties', $schema);
    foreach ([
      'title',
      'body',
      'field_teaser',
      'field_publication_date',
      'field_news_type',
      'field_contacts',
      'field_content_paragraphs',
    ] as $field) {
      $this->assertArrayHasKey($field, $schema['properties'], "Field $field present.");
    }
  }

  /**
   * Asserts the title field receives a non-empty per-field schema.
   */
  public function testTitleHasStringTypeFromCoreLeaf(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // After Task 1 we accept any non-empty per-field schema; Tasks 2-4 tighten.
    // @todo Task 2: assert $schema['properties']['title']['type'] === 'string'
    //   once envelope wrapping lands.
    $this->assertNotEmpty($schema['properties']['title'], 'title schema is non-empty.');
  }

  /**
   * Asserts internal base fields (uid, vid, langcode, ...) are excluded.
   */
  public function testInternalFieldsExcluded(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    foreach ([
      'uid',
      'vid',
      'langcode',
      'revision_uid',
      'revision_default',
      'default_langcode',
      'revision_translation_affected',
    ] as $internal) {
      $this->assertArrayNotHasKey($internal, $schema['properties'],
        "Internal field $internal is excluded.");
    }
  }

}
