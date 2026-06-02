<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the DraftEntityBuilder service end-to-end.
 *
 * Builds an unsaved node from an LLM-shaped fields map and asserts the result
 * carries the expected bundle, scalar fields, and inline child paragraphs.
 * Does NOT call $node->save(): the builder's contract is "produce an unsaved
 * entity"; save-time behaviour is covered by DraftingPluginSaveTest in the
 * ExistingSite suite.
 *
 * Module list aligns with InlineEntityHydratorTest so the shared
 * `oe_ai_assistant_test` fixture (oe_news + text_block + quote_block) is
 * available.
 */
#[Group('oe_ai_assistant')]
class DraftEntityBuilderTest extends KernelTestBase {

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
    'ai_agents',
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
      'oe_ai_assistant_test',
    ]);
  }

  /**
   * Returns the builder service from the container.
   */
  private function builder(): DraftEntityBuilder {
    return $this->container->get(DraftEntityBuilder::class);
  }

  /**
   * Builds an unsaved node and populates scalar fields.
   */
  public function testBuildsUnsavedNodeWithScalarFields(): void {
    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'A drafted title']],
      'field_news_type' => [['value' => 'announcement']],
    ]);

    $this->assertNull($node->id(), 'Entity is unsaved.');
    $this->assertSame('oe_news', $node->bundle());
    $this->assertSame('A drafted title', $node->getTitle());
    $this->assertSame('announcement', $node->get('field_news_type')->value);
  }

  /**
   * Attaches inline paragraphs via the hydrator collaborator.
   */
  public function testAttachesInlineParagraphs(): void {
    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'With paragraphs']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => 'Inline body']],
        ],
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_text' => [['value' => 'A quote']],
        ],
      ],
    ]);

    $items = $node->get('field_content_paragraphs');
    $this->assertCount(2, $items, 'Both inline paragraphs attached.');
    $this->assertSame('text_block', $items->get(0)->entity->bundle());
    $this->assertSame('Inline body', $items->get(0)->entity->get('field_text_body')->value);
    $this->assertSame('quote_block', $items->get(1)->entity->bundle());
    $this->assertSame('A quote', $items->get(1)->entity->get('field_quote_text')->value);
  }

  /**
   * Rejects unknown entity types with a clear error.
   */
  public function testThrowsOnUnknownEntityType(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown entity type "does_not_exist".');
    $this->builder()->fromLlmFields('does_not_exist', 'oe_news', []);
  }

  /**
   * Rejects bundleless entity types with a clear error.
   */
  public function testThrowsOnBundlelessEntityType(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Entity type "user" has no bundle key');
    $this->builder()->fromLlmFields('user', 'user', []);
  }

}
