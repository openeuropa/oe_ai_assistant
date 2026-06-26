<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
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
   * An empty template id auto-selects the latest template for the bundle.
   */
  public function testEmptyTemplateIdAutoPicksLatestTemplate(): void {
    // oe_news has news_default and news_with_paragraphs; latest by id is
    // news_with_paragraphs (title, field_teaser, field_content_paragraphs).
    $groups = $this->provider()->groups('node', 'oe_news', '');

    $this->assertSame(['title', 'field_teaser'], $this->mainFields($groups));
    $this->assertContains('field_content_paragraphs', array_column($groups, 'groupId'));
  }

  /**
   * A bundle with no template falls back to the full grouping.
   */
  public function testBundleWithoutTemplateReturnsFullGroups(): void {
    // oe_contact has no drafting template, so the full schema is used.
    $main = $this->mainFields($this->provider()->groups('node', 'oe_contact', ''));

    $this->assertContains('field_contact_name', $main);
  }

  /**
   * An unknown template id falls back to the full grouping.
   */
  public function testUnknownTemplateIdFallsBackToFullGroups(): void {
    $main = $this->mainFields($this->provider()->groups('node', 'oe_news', 'does_not_exist'));

    $this->assertContains('field_body', $main);
  }

  /**
   * A template for a different content type is ignored (full grouping).
   */
  public function testContentTypeMismatchFallsBackToFullGroups(): void {
    // news_default targets oe_news; applied to oe_contact it must not prune.
    $main = $this->mainFields($this->provider()->groups('node', 'oe_contact', 'news_default'));

    $this->assertContains('field_contact_name', $main);
  }

}
