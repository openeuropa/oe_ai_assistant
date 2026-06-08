<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the leaf-level normalisation produced by EntityJsonSchemaComposer.
 *
 * Tests that EntityJsonSchemaComposer walks a stub `oe_news` node, returns
 * a flat per-field map under `properties`, normalises each leaf via Drupal
 * core's `'json_schema'` format, and excludes the internal base fields that
 * core already filters out via TypedDataInternalPropertiesHelper.
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
      'field_body',
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
    $title = $schema['properties']['title'];

    // Title is array-wrapped, items are objects with a 'value' string.
    $this->assertSame('array', $title['type']);
    $this->assertSame(1, $title['maxItems']);
    $this->assertSame('object', $title['items']['type']);
    $this->assertSame('string', $title['items']['properties']['value']['type']);
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
  public function testFormattedTextFieldHasValueAndFormatProperties(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $body = $schema['properties']['field_body'];

    // The body field is array-wrapped with multi-property items.
    $this->assertSame('array', $body['type']);
    $this->assertSame('object', $body['items']['type']);
    $this->assertArrayHasKey('value', $body['items']['properties']);
    $this->assertArrayHasKey('format', $body['items']['properties']);
    $this->assertSame('string', $body['items']['properties']['value']['type']);
  }

  /**
   * Asserts single-property fields are NOT collapsed.
   */
  public function testTitleIsArrayWrappedNotCollapsed(): void {
    // Sanity: the old single-property collapse is gone.
    // title used to be {type: "string"}; now it's array-wrapped.
    $schema = $this->composer()->compose('node', 'oe_news');
    $title = $schema['properties']['title'];

    $this->assertSame('array', $title['type'],
      'No single-property collapse, title must be array-wrapped.');
    $this->assertArrayHasKey('value', $title['items']['properties']);
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
      // Walk into array wrappers (every field) to inspect the items schema,
      // and into oneOf variants for paragraph fields.
      $candidates = [];
      $items = $fieldSchema['items'] ?? NULL;
      if ($items === NULL) {
        continue;
      }
      if (isset($items['oneOf'])) {
        foreach ($items['oneOf'] as $variant) {
          $candidates[] = $variant;
        }
      }
      else {
        $candidates[] = $items;
      }
      foreach ($candidates as $candidate) {
        if (($candidate['type'] ?? NULL) === 'object') {
          $this->assertNotSame(
            [],
            $candidate['properties'] ?? NULL,
            "Field $fieldName must not have an empty properties object."
          );
        }
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

    // field_news_type is single-cardinality list_string. Enum lives on
    // properties.value (leaf), not on the object envelope.
    $this->assertSame('array', $newsType['type']);
    $this->assertSame('object', $newsType['items']['type']);
    $this->assertSame('string', $newsType['items']['properties']['value']['type']);
    $this->assertEqualsCanonicalizing(
      ['press_release', 'announcement', 'update'],
      $newsType['items']['properties']['value']['enum'],
    );
  }

  /**
   * Asserts string fields surface the storage max_length as maxLength.
   */
  public function testStringFieldGetsMaxLengthFromStorage(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    // title's storage max_length is 255 (Drupal core default). maxLength
    // lives on properties.value (leaf).
    $this->assertSame(255, $schema['properties']['title']['items']['properties']['value']['maxLength']);
  }

  /**
   * Asserts datetime fields with datetime_type=date use format "date".
   */
  public function testDateOnlyDateTimeFieldGetsDateFormat(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $value = $schema['properties']['field_publication_date'];
    // Date-only datetime: format on properties.value.
    $this->assertSame('array', $value['type']);
    $this->assertSame('string', $value['items']['properties']['value']['type']);
    $this->assertSame('date', $value['items']['properties']['value']['format']);
  }

  /**
   * Asserts the field instance description carries through to the schema.
   */
  public function testFieldDescriptionsCarryThrough(): void {
    $schema = $this->composer()->compose('node', 'oe_news');

    // Description-preferred path: field_teaser has both a description and
    // label; the description (lowercase 't') wins over the label "Teaser"
    // (capital T). Description lives on the array wrapper (the field), not
    // the item schema.
    $teaser = $schema['properties']['field_teaser'];
    $this->assertStringContainsString('teaser', $teaser['description'] ?? '');

    // Label-fallback path: field_publication_date has empty description so the
    // label "Publication date" reaches the schema.
    $pubDate = $schema['properties']['field_publication_date'];
    $this->assertSame('Publication date', $pubDate['description'] ?? '');
  }

  /**
   * Asserts datetime fields with datetime_type=datetime use format "date-time".
   */
  public function testDateTimeFieldGetsDateTimeFormat(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $value = $schema['properties']['field_publication_datetime'];
    // datetime_type=datetime: format on properties.value.
    $this->assertSame('array', $value['type']);
    $this->assertSame('string', $value['items']['properties']['value']['type']);
    $this->assertSame('date-time', $value['items']['properties']['value']['format']);
  }

  /**
   * Asserts entity_reference fields surface target_type and target_bundles.
   */
  public function testEntityReferenceFieldGetsTargetMetadata(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $contacts = $schema['properties']['field_contacts'];

    // Reference fields emit denormalize-shape items: properties.target_id +
    // x-targetType.
    $this->assertSame('array', $contacts['type']);
    $items = $contacts['items'];
    $this->assertSame('node', $items['x-targetType']);
    $this->assertSame('integer', $items['properties']['target_id']['type']);
  }

  /**
   * Asserts paragraph reference fields recurse into all allowed target bundles.
   */
  public function testParagraphReferenceRecursesIntoAllowedBundles(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $paragraphs = $schema['properties']['field_content_paragraphs'];
    $items = $paragraphs['items'];

    // Paragraphs emit oneOf over bundles. Each variant is the bundle's
    // composed schema with `type` constrained to the bundle name.
    $this->assertSame('paragraph', $items['x-targetType']);
    $this->assertArrayHasKey('oneOf', $items);
    $this->assertCount(2, $items['oneOf'], 'One variant per allowed bundle.');

    // Find the text_block variant by its constrained type.
    $textBlockVariant = NULL;
    $quoteBlockVariant = NULL;
    foreach ($items['oneOf'] as $variant) {
      $bundleConst = $variant['properties']['type']['items']['properties']['target_id']['const'] ?? NULL;
      if ($bundleConst === 'text_block') {
        $textBlockVariant = $variant;
      }
      if ($bundleConst === 'quote_block') {
        $quoteBlockVariant = $variant;
      }
    }
    $this->assertNotNull($textBlockVariant, 'oneOf includes a text_block variant.');
    $this->assertNotNull($quoteBlockVariant, 'oneOf includes a quote_block variant.');

    // Each variant carries the bundle's editorially meaningful fields.
    $this->assertArrayHasKey('field_text_body', $textBlockVariant['properties']);
    $this->assertArrayHasKey('field_quote_text', $quoteBlockVariant['properties']);
    $this->assertArrayHasKey('field_quote_attribution', $quoteBlockVariant['properties']);
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
    // The created and changed fields are auto-managed timestamps. The LLM
    // should never see them - emitting bogus values would silently overwrite
    // Drupal's revision tracking via $serializer->deserialize() in the save
    // endpoint.
    $this->assertArrayNotHasKey('created', $schema['properties'],
      'created is excluded from the schema.');
    $this->assertArrayNotHasKey('changed', $schema['properties'],
      'changed is excluded from the schema.');
  }

  /**
   * Asserts image fields route through the reference path.
   */
  public function testImageFieldRoutesAsReference(): void {
    // Image fields extend EntityReferenceItem (target_type=file). Routes
    // through composeReferenceItem and emits target_id + alt + title.
    $schema = $this->composer()->compose('node', 'oe_news');
    $image = $schema['properties']['field_news_image'];

    $this->assertSame('array', $image['type']);
    $items = $image['items'];
    $this->assertSame('object', $items['type']);
    $this->assertSame('file', $items['x-targetType']);
    $this->assertSame('integer', $items['properties']['target_id']['type']);
    $this->assertSame('string', $items['properties']['alt']['type']);
    $this->assertSame('string', $items['properties']['title']['type']);

    // Image is NOT entity_reference_revisions; no oneOf/recursion.
    $this->assertArrayNotHasKey('oneOf', $items,
      'Plain reference fields do not recurse into bundles.');
  }

  /**
   * Asserts link fields emit a wrapped object schema with uri and title.
   */
  public function testLinkFieldIsObjectWithUriAndTitle(): void {
    // Link fields are multi-property (uri + title) with NO class hierarchy
    // under EntityReferenceItem. Route through composeItem and emit a wrapped
    // object schema.
    $schema = $this->composer()->compose('node', 'oe_news');
    $link = $schema['properties']['field_news_link'];

    $this->assertSame('array', $link['type']);
    $this->assertSame('object', $link['items']['type']);
    $this->assertArrayHasKey('uri', $link['items']['properties']);
    $this->assertArrayHasKey('title', $link['items']['properties']);
  }

  /**
   * Taxonomy term references exercise the bundle-info enumeration fallback.
   *
   * The field_news_tags field has no target_bundles set in handler_settings,
   * so the composer falls through to
   * bundleInfo->getBundleInfo('taxonomy_term'). Asserts the schema picks them
   * up as x-targetType (the bundle list itself is not surfaced for
   * non-revisions references, only the entity type). Multi-cardinality (-1)
   * wraps as array.
   */
  public function testTaxonomyReferenceUsesVocabularyAsBundle(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    $tags = $schema['properties']['field_news_tags'];

    $this->assertSame('array', $tags['type']);
    $this->assertArrayNotHasKey('maxItems', $tags,
      'Cardinality unlimited has no maxItems.');
    $items = $tags['items'];
    $this->assertSame('taxonomy_term', $items['x-targetType']);
    $this->assertSame('integer', $items['properties']['target_id']['type']);
  }

  /**
   * Locks the new contract: every field is an array-of-objects.
   *
   * The denormalize-input shape requires that no field collapses to a leaf
   * primitive: the composer must emit
   * `{type: "array", items: {type: "object", ...}}` for every non-skipped
   * field on the bundle. This invariant catches accidental regressions to
   * the old single-property-collapse behavior.
   */
  public function testEveryFieldIsArrayOfObjects(): void {
    $schema = $this->composer()->compose('node', 'oe_news');
    foreach ($schema['properties'] as $fieldName => $fieldSchema) {
      $this->assertSame('array', $fieldSchema['type'] ?? NULL,
        "Field '$fieldName' must be wrapped as 'type: array'.");
      $this->assertArrayHasKey('items', $fieldSchema,
        "Field '$fieldName' must have an 'items' key.");
      $this->assertSame('object', $fieldSchema['items']['type'] ?? NULL,
        "Field '$fieldName' items must be 'type: object'.");

      // For paragraph fields the items wrap a oneOf and each variant must also
      // be type: object so the discriminator pattern stays intact.
      if (isset($fieldSchema['items']['oneOf'])) {
        foreach ($fieldSchema['items']['oneOf'] as $i => $variant) {
          $this->assertSame('object', $variant['type'] ?? NULL,
            "Field '$fieldName' oneOf variant $i must be 'type: object'.");
        }
      }
    }
  }

}
