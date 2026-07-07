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
    // The payload sent to the LLM shrinks too, not just the property count.
    $this->assertLessThan(
      strlen(json_encode($schema)),
      strlen(json_encode($filtered)),
      'Filtering against a template shrinks the encoded schema.',
    );
  }

  /**
   * Paragraph variants are pruned to the template's nested fields per bundle.
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
   * A variant's required list is recomputed against the kept fields.
   *
   * The fixture marks field_quote_text as required. Kept, it stays in the
   * variant's required list (and the discriminator is never added); dropped,
   * the required key is removed rather than left as an empty list.
   */
  public function testVariantRequiredRecomputedAgainstKeptFields(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    // news_with_paragraphs keeps both quote_block fields.
    $filtered = $this->filter()->filter($schema, $this->template('news_with_paragraphs'));
    $byBundle = $this->variantsByBundle(
      $filtered['properties']['field_content_paragraphs']['items']['oneOf'],
    );
    $this->assertSame(['field_quote_text'], $byBundle['quote_block']['required']);

    // An unsaved template keeping only the attribution drops the required
    // field, so the variant must not keep a stale or empty required list.
    $template = AiDraftingTemplate::create([
      'id' => 'attribution_only',
      'label' => 'Attribution only',
      'content_type' => 'oe_news',
      'fields' => [
        'field_content_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'items' => [
            [
              'entity_type' => 'paragraph',
              'bundle' => 'quote_block',
              'prompt' => 'Quote.',
              'fields' => [
                'field_quote_attribution' => ['prompt' => 'Who said it.'],
              ],
            ],
          ],
        ],
      ],
    ]);
    $filtered = $this->filter()->filter($schema, $template);
    $byBundle = $this->variantsByBundle(
      $filtered['properties']['field_content_paragraphs']['items']['oneOf'],
    );
    $this->assertArrayNotHasKey('required', $byBundle['quote_block']);
  }

  /**
   * A bundle listed without nested fields keeps its whole variant.
   */
  public function testNoFieldsBundleKeepsWholeVariant(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'whole_bundle',
      'label' => 'Whole bundle',
      'content_type' => 'oe_news',
      'fields' => [
        'field_content_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'items' => [
            [
              'entity_type' => 'paragraph',
              'bundle' => 'text_block',
              'prompt' => 'Text.',
            ],
          ],
        ],
      ],
    ]);

    $schema = $this->composer()->compose('node', 'oe_news');
    $filtered = $this->filter()->filter($schema, $template);

    $oneOf = $filtered['properties']['field_content_paragraphs']['items']['oneOf'];
    // Only the listed bundle survives, with its full composed property set.
    $this->assertCount(1, $oneOf);
    $composed = $this->variantsByBundle(
      $schema['properties']['field_content_paragraphs']['items']['oneOf'],
    );
    $this->assertSame(
      array_keys($composed['text_block']['properties']),
      array_keys($oneOf[0]['properties']),
    );
  }

  /**
   * Unknown template fields are skipped; nothing kept means zero groups.
   */
  public function testAbsentTemplateFieldIsSkipped(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'absent_field',
      'label' => 'Absent field',
      'content_type' => 'oe_news',
      'fields' => ['no_such_field' => ['prompt' => 'Ghost.']],
    ]);

    $schema = $this->composer()->compose('node', 'oe_news');

    $filtered = $this->filter()->filter($schema, $template);
    $this->assertSame([], $filtered['properties']);
    $this->assertArrayNotHasKey('required', $filtered);

    $this->assertSame([], $this->filter()->splitIntoGroups($schema, $template));
  }

  /**
   * Nested fields of a single-bundle inline reference are pruned too.
   *
   * The field_contacts field is an entity_reference (inline_entity_form) whose
   * only target bundle is oe_contact, so the composer emits the bundle schema
   * directly on items, without a oneOf wrapper. The template's nested field
   * restriction applies to that shape as well.
   */
  public function testFilterPrunesSingleBundleInlineReferenceNestedFields(): void {
    $template = AiDraftingTemplate::create([
      'id' => 'news_with_contact',
      'label' => 'News with contact',
      'content_type' => 'oe_news',
      'fields' => [
        'title' => ['prompt' => 'Headline.'],
        'field_contacts' => [
          'type' => 'entity_reference',
          'items' => [
            [
              'entity_type' => 'node',
              'bundle' => 'oe_contact',
              'prompt' => 'The contact person for the article.',
              'fields' => [
                'title' => ['prompt' => 'Contact label.'],
                'field_contact_name' => ['prompt' => 'Contact name.'],
              ],
            ],
          ],
        ],
      ],
    ]);
    $template->save();
    $schema = $this->composer()->compose('node', 'oe_news');
    $items = $this->filter()->filter($schema, $template)['properties']['field_contacts']['items'];
    // Shape precondition: single target bundle, so no oneOf to prune.
    $this->assertArrayNotHasKey('oneOf', $items);
    $this->assertSame('node', $items['x-targetType']);
    // Only the template's nested fields and the bundle discriminator survive.
    $this->assertEqualsCanonicalizing(
      ['title', 'field_contact_name', 'type'],
      array_keys($items['properties']),
    );
  }

  /**
   * A reference whose template bundles match no variant keeps the field whole.
   *
   * Possible through config drift: the template validated at save time, but
   * the field's allowed bundles changed afterwards. An empty oneOf would be
   * unsatisfiable, so the field must stay unpruned instead.
   */
  public function testNoMatchingVariantKeepsFieldWhole(): void {
    // Unsaved template, so save-time validation does not reject the bundle.
    $template = AiDraftingTemplate::create([
      'id' => 'drifted',
      'label' => 'Drifted',
      'content_type' => 'oe_news',
      'fields' => [
        'field_content_paragraphs' => [
          'type' => 'entity_reference_revisions',
          'items' => [
            [
              'entity_type' => 'paragraph',
              'bundle' => 'no_such_block',
              'prompt' => 'Text.',
            ],
          ],
        ],
      ],
    ]);

    $schema = $this->composer()->compose('node', 'oe_news');
    $filtered = $this->filter()->filter($schema, $template);

    $items = $filtered['properties']['field_content_paragraphs']['items'];
    $this->assertNotSame([], $items['oneOf'], 'oneOf must not be empty.');
    $this->assertCount(2, $items['oneOf'], 'Both composed variants survive.');
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
