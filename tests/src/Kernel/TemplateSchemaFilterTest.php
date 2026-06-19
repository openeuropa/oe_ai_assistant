<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiDraftingTemplate;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\TemplateSchemaFilterInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests TemplateSchemaFilter pruning, grouping, and defaults.
 *
 * The oe_ai_assistant_test module provides the oe_news content type, the
 * paragraph types, and the news_default / news_with_paragraphs templates,
 * so real composed schemas can be filtered against real templates without a
 * running site.
 */
#[Group('oe_ai_assistant')]
class TemplateSchemaFilterTest extends KernelTestBase {

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
   * Returns the schema composer.
   */
  private function composer(): EntityJsonSchemaComposer {
    return $this->container->get(EntityJsonSchemaComposer::class);
  }

  /**
   * Returns the filter under test.
   */
  private function filter(): TemplateSchemaFilterInterface {
    return $this->container->get(TemplateSchemaFilterInterface::class);
  }

  /**
   * Loads a drafting template fixture by ID.
   */
  private function template(string $id): AiDraftingTemplate {
    $template = AiDraftingTemplate::load($id);
    $this->assertInstanceOf(AiDraftingTemplate::class, $template, "Template $id loaded.");
    return $template;
  }

  /**
   * Filtering keeps only the template's top-level fields, in template order.
   */
  public function testFilterPrunesTopLevelToTemplateFields(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $filtered = $this->filter()->filter($schema, $this->template('news_default'));

    $this->assertSame(
      ['title', 'field_teaser', 'field_body'],
      array_keys($filtered['properties']),
    );
    // 'required' is recomputed against the kept fields: title is required and
    // kept, the other required fields of the bundle are dropped.
    $this->assertSame(['title'], $filtered['required'] ?? []);
  }

  /**
   * The filtered schema is materially smaller than the composed one.
   */
  public function testFilteredSchemaIsSmallerThanComposed(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $filtered = $this->filter()->filter($schema, $this->template('news_default'));

    $this->assertLessThan(
      count($schema['properties']),
      count($filtered['properties']),
      'Filtering against a template drops fields.',
    );
  }

  /**
   * Paragraph variants are pruned to the template's nested fields per bundle.
   *
   * The news_with_paragraphs template lists text_block (twice) and quote_block,
   * each with a subset of its fields. The field allows exactly those two
   * bundles, so no variant is dropped, but each variant must shrink to its
   * nested fields plus the bundle discriminator, dropping paragraph base fields
   * the template omits (status, parent_id, behavior_settings, ...).
   */
  public function testFilterPrunesParagraphVariantsToNestedFields(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $filtered = $this->filter()->filter($schema, $this->template('news_with_paragraphs'));

    $items = $filtered['properties']['field_content_paragraphs']['items'];
    $this->assertArrayHasKey('oneOf', $items);
    // text_block listed twice in the template still yields one variant.
    $byBundle = $this->variantsByBundle($items['oneOf']);
    $this->assertSame(['text_block', 'quote_block'], array_keys($byBundle));

    // text_block: only field_text_body survives, plus the 'type' discriminator.
    $this->assertEqualsCanonicalizing(
      ['field_text_body', 'type'],
      array_keys($byBundle['text_block']['properties']),
    );
    // quote_block: only its two template fields survive (+ discriminator).
    $this->assertEqualsCanonicalizing(
      ['field_quote_text', 'field_quote_attribution', 'type'],
      array_keys($byBundle['quote_block']['properties']),
    );
  }

  /**
   * Grouping runs the composer's heuristic over the filtered schema.
   *
   * All-scalar template -> a single main_fields group. A template with a
   * paragraph field -> main_fields plus one group for that field.
   */
  public function testSplitIntoGroupsRunsHeuristicOnFilteredSchema(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    $groups = $this->filter()->splitIntoGroups($schema, $this->template('news_default'));
    $this->assertCount(1, $groups);
    $this->assertSame('main_fields', $groups[0]['groupId']);
    $this->assertSame(['title', 'field_teaser', 'field_body'], $groups[0]['fieldNames']);

    $groups = $this->filter()->splitIntoGroups($schema, $this->template('news_with_paragraphs'));
    $this->assertSame(
      ['main_fields', 'field_content_paragraphs'],
      array_column($groups, 'groupId'),
    );
    $this->assertSame(['title', 'field_teaser'], $groups[0]['fieldNames']);
    $this->assertSame(['field_content_paragraphs'], $groups[1]['fieldNames']);
    // The slice carries the filtered field schema, not the full composed one.
    $this->assertArrayHasKey(
      'field_content_paragraphs',
      $groups[1]['schemaSlice']['properties'],
    );
  }

  /**
   * Defaults return the template's resolved default values.
   */
  public function testDefaultsReturnResolvedTemplateValues(): void {
    $defaults = $this->filter()->defaults($this->template('news_default'));

    $this->assertSame('en', $defaults['langcode']);
    $this->assertSame('draft', $defaults['moderation_state']);
  }

  /**
   * Indexes oneOf variants by their discriminator bundle (the 'type' const).
   *
   * @param array $variants
   *   The oneOf list from a composed reference field's items.
   *
   * @return array
   *   Variants keyed by bundle machine name.
   */
  private function variantsByBundle(array $variants): array {
    $byBundle = [];
    foreach ($variants as $variant) {
      $bundle = $variant['properties']['type']['items']['properties']['target_id']['const'];
      $byBundle[$bundle] = $variant;
    }
    return $byBundle;
  }

}
