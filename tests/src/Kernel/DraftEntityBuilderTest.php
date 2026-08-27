<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\DraftEntityBuilder;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
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
   * Resolves a missing format, honouring the user's format permissions.
   *
   * Proves the resolver skips an allowed_formats entry the user is not
   * permitted to use, rather than picking the first entry regardless of
   * access.
   */
  public function testResolvesMissingFormatOnBuiltEntityField(): void {
    FilterFormat::create(['format' => 'oe_test_permitted', 'name' => 'Permitted', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_forbidden', 'name' => 'Forbidden', 'weight' => 1])->save();

    $fieldConfig = FieldConfig::loadByName('node', 'oe_news', 'field_body');
    $fieldConfig->setSetting('allowed_formats', ['oe_test_forbidden', 'oe_test_permitted']);
    $fieldConfig->save();

    Role::create(['id' => 'oe_test_role', 'label' => 'Test role'])
      ->grantPermission('use text format oe_test_permitted')
      ->save();
    // Uid 1 bypasses all permission checks; consume it so the real test user
    // below is subject to the ordinary permission check the test exercises.
    User::create(['name' => 'Uid 1 placeholder'])->save();
    $user = User::create(['name' => 'Format tester', 'roles' => ['oe_test_role']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'Formatted body']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>Body copy.</p>']],
    ]);

    $this->assertSame(
      'oe_test_permitted',
      $node->get('field_body')->format,
      'Resolver skips the earlier allowed_formats entry the user may not use.',
    );
  }

  /**
   * Leaves an already-set format untouched.
   *
   * The resolver only fills in a missing format; it must not override a
   * format the LLM payload (or a template default) already supplied.
   */
  public function testPreservesAlreadySetFormat(): void {
    FilterFormat::create(['format' => 'oe_test_existing', 'name' => 'Existing', 'weight' => 0])->save();

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'Formatted body']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>Body copy.</p>', 'format' => 'oe_test_existing']],
    ]);

    $this->assertSame('oe_test_existing', $node->get('field_body')->format);
  }

  /**
   * Picks the first allowed_formats entry when the user may use more than one.
   *
   * With no permission gap to disambiguate, this proves the resolver honours
   * allowed_formats order rather than, say, filter_formats()' weight order.
   */
  public function testResolvesMissingFormatToFirstAllowedFormatWhenBothPermitted(): void {
    FilterFormat::create(['format' => 'oe_test_first', 'name' => 'First', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'oe_test_second', 'name' => 'Second', 'weight' => 1])->save();

    $fieldConfig = FieldConfig::loadByName('node', 'oe_news', 'field_body');
    $fieldConfig->setSetting('allowed_formats', ['oe_test_first', 'oe_test_second']);
    $fieldConfig->save();

    Role::create(['id' => 'oe_test_role_both', 'label' => 'Test role both'])
      ->grantPermission('use text format oe_test_first')
      ->grantPermission('use text format oe_test_second')
      ->save();
    // Uid 1 bypasses all permission checks; consume it so the real test user
    // below is subject to the ordinary permission check the test exercises.
    User::create(['name' => 'Uid 1 placeholder'])->save();
    $user = User::create(['name' => 'Format tester both', 'roles' => ['oe_test_role_both']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $node = $this->builder()->fromLlmFields('node', 'oe_news', [
      'title' => [['value' => 'Formatted body']],
      'field_news_type' => [['value' => 'announcement']],
      'field_body' => [['value' => '<p>Body copy.</p>']],
    ]);

    $this->assertSame('oe_test_first', $node->get('field_body')->format);
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
