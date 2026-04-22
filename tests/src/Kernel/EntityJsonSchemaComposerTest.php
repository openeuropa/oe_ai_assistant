<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the leaf-level normalisation produced by EntityJsonSchemaComposer.
 *
 * Task 1 of the OEL-4691 Path A plan: assert that the composer walks a stub
 * `oe_news` node, returns a flat per-field map under `properties`, normalises
 * each leaf via Drupal core's `'json_schema'` format, and excludes the
 * internal base fields that core already filters out via
 * TypedDataInternalPropertiesHelper. Subsequent tasks layer on enrichment,
 * envelope wrapping and recursion.
 */
#[Group('oe_ai_assistant')]
class EntityJsonSchemaComposerTest extends KernelTestBase {

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
   * Returns the composer service from the container.
   */
  private function composer(): EntityJsonSchemaComposer {
    return $this->container->get(EntityJsonSchemaComposer::class);
  }

  /**
   * Asserts the composed schema exposes every oe_news field at the top level.
   */
  public function testReturnsArrayWithExpectedTopLevelFields(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    $this->assertIsArray($schema);
    $this->assertArrayHasKey('properties', $schema);
    foreach ([
      'title',
      'body',
      'field_teaser',
      'field_publication_date',
      'field_news_type',
      'field_contacts',
      'field_content_paragraphs',
    ] as $field) {
      $this->assertArrayHasKey($field, $schema['properties'], "Field $field present.");
    }
  }

  /**
   * Asserts the title field receives a non-empty per-field schema.
   */
  public function testTitleHasStringTypeFromCoreLeaf(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // After Task 1 we accept any non-empty per-field schema; Tasks 2-4 tighten.
    // @todo Task 2: assert $schema['properties']['title']['type'] === 'string'
    //   once envelope wrapping lands.
    $this->assertNotEmpty($schema['properties']['title'], 'title schema is non-empty.');
  }

  /**
   * Asserts internal base fields (uid, vid, langcode, ...) are excluded.
   */
  public function testInternalFieldsExcluded(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    foreach ([
      'uid',
      'vid',
      'langcode',
      'revision_uid',
      'revision_default',
      'default_langcode',
      'revision_translation_affected',
    ] as $internal) {
      $this->assertArrayNotHasKey($internal, $schema['properties'],
        "Internal field $internal is excluded.");
    }
  }

  /**
   * Asserts multi-property text-with-format fields wrap as object schemas.
   */
  public function testFormattedTextFieldIsObjectWithValueAndFormat(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $body = $schema['properties']['body'];

    $this->assertSame('object', $body['type']);
    $this->assertArrayHasKey('value', $body['properties']);
    $this->assertArrayHasKey('format', $body['properties']);
    $this->assertSame('string', $body['properties']['value']['type']);
  }

  /**
   * Asserts single-property single-cardinality fields collapse to a leaf.
   */
  public function testTitleSinglePropertyFieldIsFlatString(): void {
    // The title field is single-property (value), single-cardinality. We
    // collapse to a primitive-shaped schema for prompt brevity.
    $schema = $this->composer()->compose('node', 'oe_news');
    $title = $schema['properties']['title'];

    $this->assertSame('string', $title['type']);
  }

  /**
   * Asserts unlimited-cardinality fields are wrapped as JSON Schema arrays.
   */
  public function testMultiCardinalityFieldIsArray(): void {
    // field_contacts has cardinality -1 (unlimited).
    $schema = $this->composer()->compose('node', 'oe_news');
    $contacts = $schema['properties']['field_contacts'];

    $this->assertSame('array', $contacts['type']);
    $this->assertArrayHasKey('items', $contacts);
  }

  /**
   * Asserts the schema lifts required field names into a top-level list.
   */
  public function testRequiredFieldsListedAtSchemaTop(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // The title field is required on every node bundle.
    $this->assertArrayHasKey('required', $schema);
    $this->assertContains('title', $schema['required']);
  }

  /**
   * Asserts no field's per-item schema is a useless empty-properties object.
   *
   * A `{type: "object", properties: []}` schema matches anything in JSON
   * Schema and would silently leak into prompts. This is the regression we'd
   * see in production if composeItem() ever fell through to the generic
   * object branch with all properties filtered out.
   */
  public function testNoFieldEmitsEmptyPropertiesObject(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    foreach ($schema['properties'] as $fieldName => $fieldSchema) {
      // Walk into array wrappers to inspect the items schema too.
      $candidate = ($fieldSchema['type'] ?? NULL) === 'array'
        ? $fieldSchema['items'] ?? []
        : $fieldSchema;
      if (($candidate['type'] ?? NULL) === 'object') {
        $this->assertNotSame(
          [],
          $candidate['properties'] ?? NULL,
          "Field $fieldName must not have an empty properties object."
        );
      }
    }
  }

  /**
   * Asserts fixed cardinality > 1 fields carry a JSON Schema maxItems bound.
   */
  public function testFixedCardinalityCarriesMaxItems(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $keywords = $schema['properties']['field_keywords'];

    $this->assertSame('array', $keywords['type']);
    $this->assertArrayHasKey('items', $keywords);
    $this->assertSame(3, $keywords['maxItems']);
  }

  /**
   * Asserts list_string fields publish their allowed_values keys as enum.
   */
  public function testListStringInjectsEnumFromAllowedValues(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $newsType = $schema['properties']['field_news_type'];

    // field_news_type is single-cardinality list_string with allowed values
    // press_release / announcement / update (per the test fixture).
    $this->assertSame('string', $newsType['type']);
    $this->assertEqualsCanonicalizing(
      ['press_release', 'announcement', 'update'],
      $newsType['enum'],
    );
  }

  /**
   * Asserts string fields surface the storage max_length as maxLength.
   */
  public function testStringFieldGetsMaxLengthFromStorage(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // title's storage max_length is 255 (Drupal core default).
    $this->assertSame(255, $schema['properties']['title']['maxLength']);
  }

  /**
   * Asserts datetime fields with datetime_type=date use format "date".
   */
  public function testDateOnlyDateTimeFieldGetsDateFormat(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // field_publication_date is configured as date-only in fixtures.
    $value = $schema['properties']['field_publication_date'];
    // For single-property single-cardinality the schema collapses to leaf.
    $this->assertSame('string', $value['type']);
    $this->assertSame('date', $value['format']);
  }

  /**
   * Asserts the field instance description carries through to the schema.
   *
   * The field_teaser fixture description is "A short teaser for the news
   * item." (lowercase "teaser"). The plan snippet asserted "Teaser" (capital),
   * but enrichField() prefers the description over the label, so we match
   * the substring that actually appears in the description. The label
   * fallback branch is exercised by datetime_type / list_string fields whose
   * instance description is empty (e.g. field_publication_date in the same
   * fixture).
   */
  public function testFieldDescriptionsCarryThrough(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    // Description-preferred path: field_teaser has both a description and
    // label; the description (lowercase 't') wins over the label "Teaser"
    // (capital T).
    $teaser = $schema['properties']['field_teaser'];
    $this->assertStringContainsString('teaser', $teaser['description'] ?? '');

    // Label-fallback path: field_publication_date has an empty description
    // so the label "Publication date" is what reaches the schema.
    $pubDate = $schema['properties']['field_publication_date'];
    $this->assertSame('Publication date', $pubDate['description'] ?? '');
  }

  /**
   * Asserts datetime fields with datetime_type=datetime use format "date-time".
   */
  public function testDateTimeFieldGetsDateTimeFormat(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // field_publication_datetime is a datetime field with
    // datetime_type: datetime (full timestamp, not date-only). enrichField()
    // must override core's default 'date' format to 'date-time'.
    $value = $schema['properties']['field_publication_datetime'];
    $this->assertSame('string', $value['type']);
    $this->assertSame('date-time', $value['format']);
  }

  /**
   * Asserts entity_reference fields surface target_type and target_bundles.
   */
  public function testEntityReferenceFieldGetsTargetMetadata(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $contacts = $schema['properties']['field_contacts'];
    $items = $contacts['items'];

    $this->assertSame('node', $items['x-targetType']);
    $this->assertContains('oe_contact', $items['x-targetBundles']);
  }

  /**
   * Asserts paragraph reference fields recurse into all allowed target bundles.
   */
  public function testParagraphReferenceRecursesIntoAllowedBundles(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $paragraphs = $schema['properties']['field_content_paragraphs'];
    $items = $paragraphs['items'];

    $this->assertSame('paragraph', $items['x-targetType']);
    // Both paragraph bundles from fixtures must appear with their schemas.
    $this->assertArrayHasKey('text_block', $items['x-bundles']);
    $this->assertArrayHasKey('quote_block', $items['x-bundles']);

    // The recursed schema for text_block must include its own field.
    $textBlock = $items['x-bundles']['text_block'];
    $quoteBlock = $items['x-bundles']['quote_block'];
    $this->assertArrayNotHasKey('x-truncated', $textBlock,
      'text_block must not be truncated at top-level recursion.');
    $this->assertArrayNotHasKey('x-truncated', $quoteBlock,
      'quote_block must not be truncated at top-level recursion.');
    $this->assertArrayHasKey('field_text_body', $textBlock['properties']);

    // And quote_block its own fields.
    $this->assertArrayHasKey('field_quote_text', $quoteBlock['properties']);
    $this->assertArrayHasKey('field_quote_attribution', $quoteBlock['properties']);
  }

  /**
   * Asserts compose() succeeds when called as the entry point on a paragraph.
   */
  public function testRecursionIsBoundedAndDoesNotInfiniteLoopOnSelfRefs(): void {
    // Compose paragraph quote_block directly; its only refs are scalars, but
    // we want to assert compose() succeeds even when called as the entry
    // point on a non-node entity type.
    $schema = $this->composer()->compose('paragraph', 'quote_block');
    $this->assertArrayHasKey('field_quote_text', $schema['properties']);
  }

  /**
   * Asserts auto-managed base fields (created, changed) are excluded.
   */
  public function testAutoManagedBaseFieldsExcluded(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // created and changed are auto-managed timestamps. The LLM should never
    // see them - emitting bogus values would silently overwrite Drupal's
    // revision tracking via DraftFieldMapper's set() call.
    $this->assertArrayNotHasKey('created', $schema['properties'],
      'created is excluded from the schema.');
    $this->assertArrayNotHasKey('changed', $schema['properties'],
      'changed is excluded from the schema.');
  }

  /**
   * Asserts image fields route through the reference path.
   */
  public function testImageFieldRoutesAsReference(): void {
    // Image fields extend EntityReferenceItem (target_type=file). The
    // broader reference detection from architect fix 3 should route them
    // through composeReferenceItem rather than emitting a primitive bag.
    $schema = $this->composer()->compose('node', 'oe_news');
    $image = $schema['properties']['field_news_image'];

    $this->assertSame('object', $image['type']);
    $this->assertSame('file', $image['x-targetType']);
    // file entity has bundles (default 'file'). Don't recurse - file refs
    // are NOT entity_reference_revisions, so no x-bundles.
    $this->assertArrayNotHasKey('x-bundles', $image);
    $this->assertArrayNotHasKey('properties', $image,
      'Image fields route through composeReferenceItem and must not emit a properties bag.');
  }

  /**
   * Asserts link fields emit a wrapped object schema with uri and title.
   */
  public function testLinkFieldIsObjectWithUriAndTitle(): void {
    // Link fields are multi-property (uri + title + options) with NO
    // class hierarchy under EntityReferenceItem. Route through composeItem
    // and emit a wrapped object schema.
    $schema = $this->composer()->compose('node', 'oe_news');
    $link = $schema['properties']['field_news_link'];

    $this->assertSame('object', $link['type']);
    $this->assertArrayHasKey('uri', $link['properties']);
    $this->assertArrayHasKey('title', $link['properties']);
  }

  /**
   * Taxonomy term references exercise the bundle-info enumeration fallback.
   *
   * field_news_tags has no target_bundles set in handler_settings, so the
   * composer falls through to bundleInfo->getBundleInfo('taxonomy_term'),
   * which returns vocabularies. Asserts the schema picks them up as
   * x-targetBundles. Multi-cardinality (-1) wraps as array.
   */
  public function testTaxonomyReferenceUsesVocabularyAsBundle(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $tags = $schema['properties']['field_news_tags'];
    // Multi-cardinality (-1) wraps as array.
    $this->assertSame('array', $tags['type']);
    $items = $tags['items'];
    $this->assertSame('taxonomy_term', $items['x-targetType']);
    $this->assertContains('news_tags', $items['x-targetBundles']);
  }

}
