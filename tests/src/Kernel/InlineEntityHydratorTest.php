<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Service\InlineEntityHydrator;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the InlineEntityHydrator service in isolation.
 *
 * The hydrator splits inline-entity fields out of an LLM payload and builds
 * UNSAVED child entities ready for appendItem onto a parent entity. The
 * fixture exercises the typical paragraph use case but the contract is
 * generic for any `entity_reference_revisions` field. This kernel test
 * asserts the public API in memory; it deliberately does NOT call
 * $node->save(). The hydrator is a pure builder. End-to-end save behaviour
 * is covered by DraftingPluginSaveTest in the ExistingSite suite.
 *
 * Module list and setUp are aligned with EntityJsonSchemaComposerTest so the
 * shared `oe_ai_assistant_test` fixture (oe_news + text_block + quote_block)
 * is available.
 */
#[Group('oe_ai_assistant')]
class InlineEntityHydratorTest extends KernelTestBase {

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
   * Returns the hydrator service from the container.
   */
  private function hydrator(): InlineEntityHydrator {
    return $this->container->get(InlineEntityHydrator::class);
  }

  /**
   * Asserts splitInlineEntityFields peels revision-ref fields off a node map.
   */
  public function testSplitInlineEntityFieldsPeelsRevisionRefs(): void {
    $fields = [
      'title' => [['value' => 'A news item']],
      'field_news_type' => [['value' => 'announcement']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => 'Body']],
        ],
      ],
    ];

    [$parent, $inlineEntities] = $this->hydrator()
      ->splitInlineEntityFields($fields, 'node', 'oe_news');

    $this->assertArrayHasKey('title', $parent);
    $this->assertArrayHasKey('field_news_type', $parent);
    $this->assertArrayNotHasKey('field_content_paragraphs', $parent,
      'Inline-entity fields are stripped from the parent map.');

    $this->assertSame(['field_content_paragraphs'], array_keys($inlineEntities),
      'Only the entity_reference_revisions field appears in slot 2.');
    $this->assertSame(
      'paragraph',
      $inlineEntities['field_content_paragraphs']['target_type'],
      'Each peeled field carries its target_type setting alongside items.',
    );
    $this->assertCount(1, $inlineEntities['field_content_paragraphs']['items']);
  }

  /**
   * Asserts buildInlineEntities returns one unsaved child entity.
   */
  public function testBuildInlineEntitiesReturnsUnsavedEntities(): void {
    $items = [
      [
        'type' => [['target_id' => 'text_block']],
        'field_text_body' => [['value' => 'A bit of body copy.']],
      ],
    ];

    $entities = $this->hydrator()->buildInlineEntities($items, 'paragraph');

    $this->assertCount(1, $entities);
    $paragraph = $entities[0];
    $this->assertInstanceOf(Paragraph::class, $paragraph);
    $this->assertTrue($paragraph->isNew(),
      'Inline entity is unsaved; parent->save() will persist it transactionally.');
    $this->assertSame('text_block', $paragraph->bundle());
    $this->assertSame(
      'A bit of body copy.',
      $paragraph->get('field_text_body')->value,
    );
  }

  /**
   * Asserts mixed-bundle items round-trip correctly.
   */
  public function testBuildInlineEntitiesHandlesMultipleBundles(): void {
    $items = [
      [
        'type' => [['target_id' => 'text_block']],
        'field_text_body' => [['value' => 'First, a paragraph.']],
      ],
      [
        'type' => [['target_id' => 'quote_block']],
        'field_quote_text' => [['value' => 'To be or not to be.']],
        'field_quote_attribution' => [['value' => 'Hamlet']],
      ],
    ];

    $entities = $this->hydrator()->buildInlineEntities($items, 'paragraph');

    $this->assertCount(2, $entities);
    [$text, $quote] = $entities;

    $this->assertSame('text_block', $text->bundle());
    $this->assertTrue($text->isNew());
    $this->assertSame(
      'First, a paragraph.',
      $text->get('field_text_body')->value,
    );

    $this->assertSame('quote_block', $quote->bundle());
    $this->assertTrue($quote->isNew());
    $this->assertSame(
      'To be or not to be.',
      $quote->get('field_quote_text')->value,
    );
    $this->assertSame(
      'Hamlet',
      $quote->get('field_quote_attribution')->value,
    );
  }

  /**
   * Asserts the recursion path inspects nested paragraph fields per bundle.
   *
   * No nested-paragraph-in-paragraph fixture exists in oe_ai_assistant_test,
   * so we cannot end-to-end build a paragraph that contains another paragraph.
   * Instead, this test exercises the structural pre-condition for that
   * recursion: splitInlineEntityFields() must, when invoked on a paragraph
   * bundle, correctly identify any entity_reference_revisions fields. Today
   * the only test paragraph types (text_block, quote_block) carry no
   * paragraph-typed fields, so the call returns an empty inlineEntityFields
   * slot and leaves all input keys in the parent slot. The assertion locks
   * in this contract: if a future fixture adds a nested paragraph field on
   * one of these bundles the second slot will populate and this test will
   * fail with a clear diff, prompting an upgrade to a real recursive case.
   */
  public function testRecursionCallPathInspectsParagraphBundleDefinitions(): void {
    // Synthetic paragraph item: pretend a text_block carries a (non-existent)
    // nested paragraph field to verify the call path.
    $synthetic = [
      'type' => [['target_id' => 'text_block']],
      'field_text_body' => [['value' => 'outer body']],
      // This key does NOT exist on text_block in the fixture; the splitter
      // should leave it in the parent map untouched (only known
      // entity_reference_revisions fields get peeled).
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_text' => [['value' => 'inner quote']],
        ],
      ],
    ];

    [$ownFields, $nested] = $this->hydrator()->splitInlineEntityFields(
      $synthetic, 'paragraph', 'text_block',
    );

    $this->assertSame([], $nested,
      'text_block has no paragraph-typed fields in the fixture, ' .
      'so the recursion bucket is empty.');
    $this->assertArrayHasKey('field_text_body', $ownFields);
    $this->assertArrayHasKey('field_content_paragraphs', $ownFields,
      'Unknown keys stay in the parent slot; splitter only peels ' .
      'recognised entity_reference_revisions fields.');

    // And confirm the structural call path: when fed an inner items array,
    // buildInlineEntities walks every item, recursing into
    // splitInlineEntityFields(\'paragraph\', $bundle, ...) per item. With the
    // current fixture that recursion always returns an empty bucket, but the
    // call still produces a valid Paragraph entity whose preSave chain would
    // own any nested children if they did exist.
    $entities = $this->hydrator()->buildInlineEntities([
      [
        'type' => [['target_id' => 'text_block']],
        'field_text_body' => [['value' => 'with would-be nested']],
      ],
    ], 'paragraph');
    $this->assertCount(1, $entities);
    $this->assertInstanceOf(Paragraph::class, $entities[0]);
    $this->assertTrue($entities[0]->isNew());
  }

  /**
   * Resolves a missing format on a hydrated child's formatted-text field.
   *
   * Proves this call site invokes the resolver too; the resolver's own
   * permission and ordering logic is covered by DraftEntityBuilderTest.
   */
  public function testResolvesMissingFormatOnHydratedChild(): void {
    $entities = $this->hydrator()->buildInlineEntities([
      [
        'type' => [['target_id' => 'text_block']],
        'field_text_body' => [['value' => '<p>Hydrated body.</p>']],
      ],
    ], 'paragraph');

    $this->assertSame('plain_text', $entities[0]->get('field_text_body')->format);
  }

  /**
   * Asserts hydrateInto attaches inline entities and strips them from the map.
   */
  public function testHydrateIntoAttachesAndStripsFromMap(): void {
    $node = Node::create(['type' => 'oe_news', 'title' => 'Stub']);

    $fields = [
      'title' => [['value' => 'Final title']],
      'field_news_type' => [['value' => 'update']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => 'Hydrated body']],
        ],
        [
          'type' => [['target_id' => 'quote_block']],
          'field_quote_text' => [['value' => 'Hydrated quote']],
          'field_quote_attribution' => [['value' => 'Anon']],
        ],
      ],
    ];

    $remaining = $this->hydrator()
      ->hydrateInto($fields, $node, 'node', 'oe_news');

    // Returned map no longer carries the inline-entity field.
    $this->assertArrayNotHasKey('field_content_paragraphs', $remaining);
    $this->assertArrayHasKey('title', $remaining);
    $this->assertArrayHasKey('field_news_type', $remaining);

    // The parent node has the paragraphs appended (still unsaved).
    $items = $node->get('field_content_paragraphs');
    $this->assertCount(2, $items);
    foreach ($items as $delta => $item) {
      $paragraph = $item->entity;
      $this->assertInstanceOf(Paragraph::class, $paragraph);
      $this->assertTrue($paragraph->isNew(),
        "Paragraph at delta $delta is unsaved.");
    }
    $this->assertSame('text_block', $items->get(0)->entity->bundle());
    $this->assertSame('quote_block', $items->get(1)->entity->bundle());
    $this->assertSame(
      'Hydrated body',
      $items->get(0)->entity->get('field_text_body')->value,
    );
    $this->assertSame(
      'Hydrated quote',
      $items->get(1)->entity->get('field_quote_text')->value,
    );
  }

  /**
   * Asserts non-array items raise an explicit error.
   */
  public function testBuildInlineEntitiesThrowsOnNonArrayItem(): void {
    $hydrator = $this->container->get(InlineEntityHydrator::class);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Inline entity item at index 0 is not an array.');
    $hydrator->buildInlineEntities(['not an array'], 'paragraph');
  }

  /**
   * Asserts items missing the bundle key raise an explicit error.
   */
  public function testBuildInlineEntitiesThrowsOnMissingBundle(): void {
    $hydrator = $this->container->get(InlineEntityHydrator::class);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Inline entity item at index 0 is missing type[0][target_id].');
    $hydrator->buildInlineEntities([['field_text_body' => [['value' => 'x']]]], 'paragraph');
  }

}
