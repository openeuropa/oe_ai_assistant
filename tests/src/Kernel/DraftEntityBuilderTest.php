<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\Tests\oe_ai_assistant\Traits\TextFormatUserTrait;
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

  use TextFormatUserTrait;

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
    'state_machine',
    'document_loader',
    'document_loader_tika',
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
   * Resolves a missing format, honouring the user's format permissions.
   *
   * The format the user may not use is the lighter one, so only the
   * permission check keeps the resolver from picking it.
   */
  public function testResolvesMissingFormatOnBuiltEntityField(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 1])->save();
    FilterFormat::create(['format' => 'oe_test_forbidden', 'name' => 'Forbidden', 'weight' => 0])->save();

    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_forbidden', 'oe_test_permitted']);

    $this->setUserWithFormats(['oe_test_permitted']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame(
      'oe_test_permitted',
      $node->get('field_body')->format,
      'Resolver skips the lighter format the user may not use.',
    );
  }

  /**
   * Keeps a supplied format the field and user allow.
   */
  public function testPreservesAlreadySetFormat(): void {
    FilterFormat::create(['format' => 'oe_test_default', 'name' => 'Default', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_existing', 'name' => 'Existing', 'weight' => 0])->save();
    $this->setUserWithFormats(['oe_test_default', 'oe_test_existing']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => 'oe_test_existing']);

    $this->assertSame('oe_test_existing', $node->get('field_body')->format);
  }

  /**
   * Picks the lightest allowed format when the user may use more than one.
   */
  public function testResolvesMissingFormatToLightestFormatWhenBothPermitted(): void {
    FilterFormat::create(['format' => 'oe_test_first', 'name' => 'First', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_second', 'name' => 'Second', 'weight' => 1])->save();

    // The field lists the heavier format first; weight decides.
    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_second', 'oe_test_first']);

    $this->setUserWithFormats(['oe_test_first', 'oe_test_second']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_first', $node->get('field_body')->format);
  }

  /**
   * Falls back to the field's format when the user may use none of them.
   */
  public function testFallsBackToFieldFormatWhenUserMayUseNone(): void {
    FilterFormat::create(['format' => 'oe_test_user_default', 'name' => 'User default', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_restricted', 'name' => 'Restricted', 'weight' => 0])->save();

    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_restricted']);

    // The user may use a format, just not the only one the field allows, so
    // the allowed/permitted intersection is empty.
    $this->setUserWithFormats(['oe_test_user_default']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame(
      'oe_test_restricted',
      $node->get('field_body')->format,
      'The field\'s format applies rather than one the field does not allow.',
    );
  }

  /**
   * Replaces a supplied format the user may not use.
   */
  public function testReplacesSuppliedFormatTheUserMayNotUse(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_forbidden', 'name' => 'Forbidden', 'weight' => 1])->save();
    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_permitted', 'oe_test_forbidden']);
    $this->setUserWithFormats(['oe_test_permitted']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => 'oe_test_forbidden']);

    $this->assertSame('oe_test_permitted', $node->get('field_body')->format);
  }

  /**
   * Replaces a supplied format the field does not allow.
   */
  public function testReplacesSuppliedFormatTheFieldDoesNotAllow(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_permitted']);
    $this->setUserWithFormats(['oe_test_permitted', 'full_html']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => 'full_html']);

    $this->assertSame('oe_test_permitted', $node->get('field_body')->format);
  }

  /**
   * Replaces a supplied format the user may not use on an inline paragraph.
   */
  public function testReplacesSuppliedFormatOnInlineParagraph(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    $this->setUserWithFormats(['oe_test_permitted']);

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'With paragraphs']],
      'field_news_type' => [['value' => 'announcement']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => '<p>Inline body</p>', 'format' => 'full_html']],
        ],
      ],
    ]);

    $paragraph = $node->get('field_content_paragraphs')->get(0)->entity;
    $this->assertSame('oe_test_permitted', $paragraph->get('field_text_body')->format);
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

  /**
   * Clears the format when every format the field allows is disabled.
   */
  public function testClearsFormatWhenFieldAllowsNoEnabledFormat(): void {
    FilterFormat::create(['format' => 'oe_test_disabled', 'name' => 'Disabled', 'status' => FALSE])->save();
    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_disabled']);
    $this->setUserWithFormats(['full_html']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => 'full_html']);

    $this->assertNull($node->get('field_body')->format);
  }

  /**
   * Prefers a format that renders HTML over a lighter one that does not.
   */
  public function testResolvesMissingFormatToFormatRenderingHtml(): void {
    FilterFormat::create([
      'format' => 'oe_test_plain',
      'name' => 'Plain',
      'weight' => -10,
      'filters' => ['filter_html_escape' => ['status' => TRUE]],
    ])->save();
    FilterFormat::create(['format' => 'oe_test_html', 'name' => 'HTML', 'weight' => 0])->save();
    $this->restrictFormats('node', 'oe_news', 'field_body', ['oe_test_plain', 'oe_test_html']);
    $this->setUserWithFormats(['oe_test_plain', 'oe_test_html']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_html', $node->get('field_body')->format);
  }

  /**
   * Builds a news node whose body has the given item.
   *
   * @param array $bodyItem
   *   The body item, as the LLM sends it.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The unsaved node.
   */
  private function buildNewsWithBody(array $bodyItem): ContentEntityInterface {
    return $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'Formatted body']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [$bodyItem],
    ]);
  }

}
