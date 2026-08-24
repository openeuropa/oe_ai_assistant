<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests DraftingSchemaProvider: template-pruned groups, with full fallback.
 */
#[Group('oe_ai_assistant')]
class DraftingSchemaProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
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
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
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
   * Returns the provider under test.
   */
  private function provider(): DraftingSchemaProviderInterface {
    return $this->container->get(DraftingSchemaProviderInterface::class);
  }

  /**
   * Returns the main_fields field names from a groups result.
   */
  private function mainFields(array $groups): array {
    return array_column($groups, 'fieldNames', 'groupId')['main_fields'] ?? [];
  }

  /**
   * A matching template prunes the groups to its fields.
   */
  public function testMatchingTemplateReturnsFilteredGroups(): void {
    $groups = $this->provider()->groups('node', 'oe_news', 'news_with_paragraphs');

    $this->assertSame(['title', 'field_teaser'], $this->mainFields($groups));
    $this->assertContains('field_content_paragraphs', array_column($groups, 'groupId'));
  }

  /**
   * With no template id, the latest enabled template for the bundle is used.
   */
  public function testMissingTemplateIdAutoPicksLatestEnabledTemplate(): void {
    // zz_disabled sorts after news_with_paragraphs but is disabled, so the
    // auto-pick must skip it and select news_with_paragraphs.
    AiDraftingTemplate::create([
      'id' => 'zz_disabled',
      'label' => 'Disabled template',
      'status' => FALSE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Title.']],
    ])->save();

    $groups = $this->provider()->groups('node', 'oe_news');

    $this->assertSame(['title', 'field_teaser'], $this->mainFields($groups));
    $this->assertContains('field_content_paragraphs', array_column($groups, 'groupId'));
  }

  /**
   * A bundle with no template falls back to the full grouping.
   */
  public function testBundleWithoutTemplateReturnsFullGroups(): void {
    // A bundle with no drafting template uses the full schema.
    NodeType::create(['type' => 'oe_empty', 'name' => 'Empty'])->save();
    $main = $this->mainFields($this->provider()->groups('node', 'oe_empty'));

    $this->assertContains('title', $main);
  }

  /**
   * An unknown template id is an error, not a silent full-schema fallback.
   */
  public function testUnknownTemplateIdThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not found');
    $this->provider()->groups('node', 'oe_news', 'does_not_exist');
  }

  /**
   * An explicitly requested disabled template is an error.
   */
  public function testDisabledTemplateIdThrows(): void {
    AiDraftingTemplate::create([
      'id' => 'zz_disabled',
      'label' => 'Disabled template',
      'status' => FALSE,
      'content_type' => 'oe_news',
      'fields' => ['title' => ['prompt' => 'Title.']],
    ])->save();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('disabled');
    $this->provider()->groups('node', 'oe_news', 'zz_disabled');
  }

  /**
   * A template for a different content type is an error.
   */
  public function testContentTypeMismatchThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('targets content type');
    $this->provider()->groups('node', 'oe_contact', 'news_default');
  }

  /**
   * Templates only apply to nodes; other entity types use the full schema.
   */
  public function testNonNodeEntityTypeSkipsTemplates(): void {
    $groups = $this->provider()->groups('paragraph', 'text_block');

    $this->assertContains('field_text_body', $this->mainFields($groups));
  }

  /**
   * An explicit template id for a non-node entity type is an error.
   */
  public function testNonNodeEntityTypeWithTemplateIdThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('only apply to node bundles');
    $this->provider()->groups('paragraph', 'text_block', 'news_default');
  }

  /**
   * Lists the enabled templates for a bundle with id, label and description.
   */
  public function testAvailableTemplatesListsEnabledTemplatesForBundle(): void {
    $templates = $this->provider()->availableTemplates('oe_news');

    $this->assertSame([
      [
        'id' => 'news_default',
        'label' => 'News article (default)',
        'description' => 'Standard news article with title, teaser, and body.',
      ],
      [
        'id' => 'news_preview_defaults',
        'label' => 'News article (preview defaults fixture)',
        'description' => 'Omits field_teaser from fields but supplies it via defaults, for exercising the preview template-defaults merge.',
      ],
      [
        'id' => 'news_with_paragraphs',
        'label' => 'News article with paragraphs',
        'description' => 'News article using rich-text and quote paragraph types.',
      ],
    ], $templates);
  }

  /**
   * A bundle without templates yields an empty list.
   */
  public function testAvailableTemplatesEmptyForBundleWithoutTemplates(): void {
    NodeType::create(['type' => 'oe_empty', 'name' => 'Empty'])->save();
    $this->assertSame([], $this->provider()->availableTemplates('oe_empty'));
  }

  /**
   * Disabled templates are not listed.
   */
  public function testAvailableTemplatesExcludesDisabledTemplates(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $template = $storage->load('news_with_paragraphs');
    $template->setStatus(FALSE);
    $template->save();

    $ids = array_column($this->provider()->availableTemplates('oe_news'), 'id');

    $this->assertSame(['news_default', 'news_preview_defaults'], $ids);
  }

  /**
   * Auto-select skips disabled templates, matching availableTemplates().
   */
  public function testAutoSelectSkipsDisabledTemplates(): void {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template');
    $template = $storage->load('news_with_paragraphs');
    $template->setStatus(FALSE);
    $template->save();

    // With news_with_paragraphs disabled, auto-select does a ksort() + end()
    // over the remaining enabled candidates and picks the alphabetically
    // last one: news_preview_defaults (not news_default), whose only field
    // is title.
    $main = $this->mainFields($this->provider()->groups('node', 'oe_news', ''));

    $this->assertSame(['title'], $main);
  }

}
