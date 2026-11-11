<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the schema groups resolved for drafting and the splitting behind them.
 *
 * Uses the oe_news content type from the test fixture to verify
 * that fields are correctly split into main_fields and per-entity
 * reference groups.
 */
#[Group('oe_ai_assistant')]
class DraftingSchemaGroupsTest extends KernelTestBase {

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
    'ai_neuron',
    'oe_ai_assistant',
    'schema_api',
    'state_machine',
    'document_loader',
    'document_loader_tika',
    'oe_ai_assistant_test',
  ];

  /**
   * An explicit template context restricts the groups to its fields.
   */
  public function testExecuteWithTemplateUsesThatTemplate(): void {
    // news_default lists title, field_teaser, field_body (all scalar).
    $groups = $this->resolveGroups('node', 'oe_news', 'news_default');

    $byId = array_column($groups, 'fieldNames', 'groupId');
    $this->assertSame(['langcode', 'type', 'title', 'field_body', 'field_teaser'], $byId['main_fields']);
    $this->assertArrayNotHasKey('field_content_paragraphs', $byId);
    $this->assertNotContains('field_news_type', $byId['main_fields']);
  }

  /**
   * An invalid template id is rejected before any group is resolved.
   */
  public function testInvalidTemplateIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not found');
    $this->resolveGroups('node', 'oe_news', 'does_not_exist');
  }

  /**
   * Without a template context, the latest template for the bundle is used.
   */
  public function testExecuteWithoutTemplateAutoPicksLatest(): void {
    // oe_news' latest template is news_with_paragraphs (title, field_teaser,
    // field_content_paragraphs), not the full schema.
    $groups = $this->resolveGroups('node', 'oe_news');

    $byId = array_column($groups, 'fieldNames', 'groupId');
    $this->assertSame(['langcode', 'type', 'title', 'field_teaser'], $byId['main_fields']);
    $this->assertArrayHasKey('field_content_paragraphs', $byId);
    $this->assertNotContains('field_body', $byId['main_fields']);
  }

  /**
   * Tests that a field holding whole entities is drafted on its own.
   *
   * One drafter writes a whole group, and a run of paragraphs is far more
   * than belongs in the call that writes the title.
   */
  public function testReferenceFieldGetsItsOwnGroup(): void {
    $groups = $this->resolveGroups('node', 'oe_news', 'news_with_paragraphs');
    $byId = array_column($groups, 'fieldNames', 'groupId');

    $this->assertSame(['field_content_paragraphs'], $byId['field_content_paragraphs']);
    $this->assertNotContains('field_content_paragraphs', $byId['main_fields']);

    // The group is named after the field, so an editor watching a draft
    // being written reads the label they know.
    $labels = array_column($groups, 'label', 'groupId');
    $this->assertSame('Content paragraphs', $labels['field_content_paragraphs']);
  }

  /**
   * Tests that a bundle with no template is described field for field.
   *
   * A plain reference points at an entity with a life of its own, so it is
   * named rather than written, and it belongs with the other scalars.
   */
  public function testBundleWithoutTemplateKeepsEveryField(): void {
    $groups = $this->resolveGroups('node', 'oe_contact');
    $byId = array_column($groups, 'fieldNames', 'groupId');

    $this->assertSame(['main_fields'], array_keys($byId));
    foreach (['field_contact_name', 'field_contact_email', 'field_contact_role'] as $name) {
      $this->assertContains($name, $byId['main_fields']);
    }
  }

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
   * Resolves the groups for a content type and template.
   */
  private function resolveGroups(string $entityTypeId, string $bundle, ?string $templateId = NULL): array {
    return $this->container->get(DraftingSchemaProviderInterface::class)->groups($entityTypeId, $bundle, $templateId);
  }

}
