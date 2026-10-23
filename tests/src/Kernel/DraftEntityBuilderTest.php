<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Tests\oe_ai_assistant\Traits\TextFormatUserTrait;
use PHPUnit\Framework\Attributes\TestWith;

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
    'ai_agents',
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
   * Uses the field's format even when the user may not use it.
   *
   * The field's configuration decides the format, as on sites that allow one
   * format per field.
   */
  public function testUsesFieldFormatRegardlessOfUserPermissions(): void {
    FilterFormat::create(['format' => 'oe_test_other', 'name' => 'Other', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_field_only', 'name' => 'Field only', 'weight' => 0])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_field_only']);
    $this->setUserWithFormats(['oe_test_other']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_field_only', $node->get('field_body')->format);
  }

  /**
   * Keeps a supplied format the field allows.
   */
  public function testKeepsSuppliedFormatTheFieldAllows(): void {
    FilterFormat::create(['format' => 'oe_test_default', 'name' => 'Default', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_existing', 'name' => 'Existing', 'weight' => 0])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_default', 'oe_test_existing']);
    $this->setUserWithFormats(['oe_test_default']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => 'oe_test_existing']);

    $this->assertSame('oe_test_existing', $node->get('field_body')->format);
  }

  /**
   * Replaces a supplied format the field is not configured for.
   *
   * Covers a format that does not exist, one that is disabled and one outside
   * the field's allowed_formats. The text renders through the resolved format
   * rather than coming out empty.
   */
  #[TestWith(['html'])]
  #[TestWith(['oe_test_disabled'])]
  #[TestWith(['oe_test_disallowed'])]
  public function testReplacesSuppliedFormatTheFieldIsNotConfiguredFor(string $format): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_disabled', 'name' => 'Disabled', 'weight' => 1, 'status' => FALSE])->save();
    FilterFormat::create(['format' => 'oe_test_disallowed', 'name' => 'Disallowed', 'weight' => 2])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_permitted', 'oe_test_disabled']);
    $this->setUserWithFormats(['oe_test_permitted', 'oe_test_disabled', 'oe_test_disallowed']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>', 'format' => $format]);

    $this->assertSame('oe_test_permitted', $node->get('field_body')->format);
    $this->assertStringContainsString('Body copy.', (string) $node->get('field_body')->processed);
  }

  /**
   * Resolves the field's first allowed format in weight order.
   *
   * The field lists the formats in the opposite order of their weights, so
   * a resolver following allowed_formats order shows up as the wrong pick.
   */
  public function testResolvesMissingFormatInWeightOrder(): void {
    FilterFormat::create(['format' => 'oe_test_first', 'name' => 'First', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_second', 'name' => 'Second', 'weight' => 1])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_second', 'oe_test_first']);
    $this->setUserWithFormats(['oe_test_first', 'oe_test_second']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_first', $node->get('field_body')->format);
  }

  /**
   * Prefers a format that renders HTML over one that escapes it.
   *
   * Plain text comes first by weight on both fields, yet drafted text is
   * HTML, so the format that renders it wins.
   */
  public function testPrefersFormatThatRendersHtml(): void {
    FilterFormat::load('plain_text')->set('weight', -20)->save();
    FilterFormat::create([
      'format' => 'oe_test_html',
      'name' => 'HTML',
      'weight' => 0,
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <strong>'],
        ],
      ],
    ])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['plain_text', 'oe_test_html']);
    $this->setUserWithFormats(['oe_test_html']);

    $restricted = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);
    $this->assertSame('oe_test_html', $restricted->get('field_body')->format);

    $this->setAllowedFormats('node', 'oe_news', 'field_body', []);
    $unrestricted = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);
    $this->assertSame('oe_test_html', $unrestricted->get('field_body')->format);
  }

  /**
   * Uses the user's default format on a field that allows any format.
   */
  public function testResolvesMissingFormatToUserDefaultOnUnrestrictedField(): void {
    FilterFormat::create(['format' => 'oe_test_unpermitted', 'name' => 'Unpermitted', 'weight' => -20])->save();
    FilterFormat::create(['format' => 'oe_test_user_default', 'name' => 'User default', 'weight' => -10])->save();
    $this->setUserWithFormats(['oe_test_user_default']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_user_default', $node->get('field_body')->format);
  }

  /**
   * Resolves the formats of inline paragraphs as well.
   */
  public function testReplacesFormatOnInlineParagraph(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    $this->setAllowedFormats('paragraph', 'text_block', 'field_text_body', ['oe_test_permitted']);
    $this->setUserWithFormats(['oe_test_permitted']);

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'With paragraphs']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => '<p>Inline body</p>', 'format' => 'html']],
        ],
      ],
    ]);

    $paragraph = $node->get('field_content_paragraphs')->get(0)->entity;
    $this->assertSame('oe_test_permitted', $paragraph->get('field_text_body')->format);
  }

  /**
   * Falls back to the user's default when no allowed format is available.
   *
   * Only a misconfigured field gets here: every format it allows is gone.
   */
  public function testFallsBackToUserDefaultWhenNoAllowedFormatExists(): void {
    FilterFormat::create(['format' => 'oe_test_user_default', 'name' => 'User default', 'weight' => -10])->save();
    $this->setAllowedFormats('node', 'oe_news', 'field_body', ['oe_test_gone']);
    $this->setUserWithFormats(['oe_test_user_default']);

    $node = $this->buildNewsWithBody(['value' => '<p>Body copy.</p>']);

    $this->assertSame('oe_test_user_default', $node->get('field_body')->format);
  }

  /**
   * Leaves the format of a field the draft does not set untouched.
   *
   * Deserialization applies field defaults, which keep their own format.
   */
  public function testLeavesUndraftedFieldDefaultUntouched(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    $fieldConfig = FieldConfig::loadByName('node', 'oe_news', 'field_body');
    $fieldConfig->setDefaultValue([['value' => '<p>Default body.</p>', 'format' => 'html']]);
    $fieldConfig->save();
    $this->setUserWithFormats(['oe_test_permitted']);

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'No body']],
      'field_news_type' => [['value' => 'announcement']],
    ]);

    $this->assertSame('html', $node->get('field_body')->format);
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
   * Builds an oe_news node with one body item.
   */
  private function buildNewsWithBody(array $body): ContentEntityInterface {
    return $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'Formatted body']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [$body],
    ]);
  }

  /**
   * Restricts a field to the given formats.
   */
  private function setAllowedFormats(string $entityTypeId, string $bundle, string $fieldName, array $formats): void {
    $fieldConfig = FieldConfig::loadByName($entityTypeId, $bundle, $fieldName);
    $fieldConfig->setSetting('allowed_formats', $formats);
    $fieldConfig->save();
  }

}
