<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;

/**
 * Documents the Drupal core 11.3 JSON Schema API behaviour for content entities.
 *
 * Pins down which of the two candidate APIs described in change record
 * https://www.drupal.org/node/3424710 is the real, working entry point in
 * this Drupal release, and produces a reference snapshot of the generated
 * schema for the `oe_news` content type at:
 *
 *   sys_get_temp_dir() . '/oe-ai-assistant-plan/core-json-schema.oe_news.json'
 *
 * WINNING API:
 *   $serializer->normalize($entity, 'json_schema')
 *
 * The `\Drupal\serialization\Serializer\Serializer::getJsonSchema()` helper
 * also works and is a thin wrapper around `normalize(..., 'json_schema')`
 * (see `JsonSchemaProviderSerializerTrait`), but it requires a `$context`
 * array argument and otherwise delegates to the same normalizer chain.
 *
 * IMPORTANT SHAPE CAVEAT:
 *   As of Drupal 11.3.7, `ContentEntityNormalizer` / `ComplexDataNormalizer`
 *   does NOT use `SchematicNormalizerTrait`. Calling
 *   `normalize($entity, 'json_schema')` therefore returns a flat map of
 *   `{field_name: schema_for_that_field}` at the top level, NOT a fully
 *   wrapped `{type: "object", properties: {...}}` JSON Schema document.
 *   Additionally, `FieldItemList` and friends do not yet implement a
 *   schematic normalizer either, so each field currently normalizes to a
 *   `{$comment: "No schema is defined for property of type ..."}` stub.
 *
 *   This means core alone is insufficient to produce a meaningful JSON
 *   Schema for an `oe_news` node today; this module will have to contribute
 *   its own normalizers (or an envelope) on top of the core output. That
 *   gap is why this module composes its own schema.
 */
#[Group('oe_ai_assistant')]
class CoreJsonSchemaTest extends KernelTestBase {

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
   * Directory under sys_get_temp_dir() where probe artifacts are written.
   */
  private const PROBE_DIR = '/oe-ai-assistant-plan/probe';

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

    // Make sure the probe artifact directory exists for every test.
    $probe_dir = sys_get_temp_dir() . self::PROBE_DIR;
    if (!is_dir($probe_dir)) {
      mkdir($probe_dir, 0777, TRUE);
    }
  }

  /**
   * Establishes the real Drupal core 11.3 JSON Schema API.
   *
   * Asserts that `$serializer->normalize($entity, 'json_schema')` is a valid,
   * working entry point on an `oe_news` stub and documents the actual top-
   * level shape: a flat associative array keyed by field machine name.
   */
  public function testNormalizeWithJsonSchemaFormat(): void {
    $serializer = $this->container->get('serializer');
    $stub = Node::create(['type' => 'oe_news']);

    $schema = $serializer->normalize($stub, 'json_schema');

    // The API returns an associative array.
    $this->assertIsArray($schema, 'normalize(..., "json_schema") returned an array.');
    $this->assertNotEmpty($schema, 'Schema is not empty.');

    // As of Drupal 11.3.7, the top level is a flat map of field_name => schema
    // rather than a wrapped {type: "object", properties: {...}} document.
    // Assert that the node's two most canonical fields (title and body)
    // appear at the top level.
    $this->assertArrayHasKey('title', $schema, 'Schema contains "title" at the top level.');
    $this->assertArrayHasKey('field_body', $schema, 'Schema contains "field_body" at the top level.');
  }

  /**
   * Locks in the expected field set for the oe_news content type.
   *
   * Uses the API established by testNormalizeWithJsonSchemaFormat() and
   * writes a pretty-printed snapshot to a deterministic artifact path so
   * downstream tasks can diff against a known baseline.
   */
  public function testOeNewsSchemaShape(): void {
    $serializer = $this->container->get('serializer');
    $stub = Node::create(['type' => 'oe_news']);

    $schema = $serializer->normalize($stub, 'json_schema');

    // Core currently returns a flat per-field map; assert that shape.
    // There is NO JSON Schema envelope (no top-level "properties" key, no
    // JSON-Schema "type" keyword: instead, any "type" key present is the
    // node's bundle-reference field, not the schema's $type).
    $this->assertIsArray($schema);
    $this->assertArrayNotHasKey(
      'properties',
      $schema,
      'Core 11.3 flat shape: there is no top-level "properties" envelope.'
    );

    // All oe_news fields (from the test module fixtures) must be present at
    // the top level of the schema.
    $expected = [
      'title',
      'field_body',
      'field_teaser',
      'field_publication_date',
      'field_news_type',
      'field_contacts',
      'field_content_paragraphs',
    ];
    foreach ($expected as $field) {
      $this->assertArrayHasKey(
        $field,
        $schema,
        sprintf('Schema includes oe_news field "%s".', $field)
      );
    }

    // Write the raw schema to a deterministic artifact path so later tasks
    // can diff against it.
    $artifact_dir = sys_get_temp_dir() . '/oe-ai-assistant-plan';
    @mkdir($artifact_dir, 0777, TRUE);
    $artifact_path = $artifact_dir . '/core-json-schema.oe_news.json';
    file_put_contents(
      $artifact_path,
      json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    $this->assertFileExists($artifact_path);
  }

  /**
   * Build a populated oe_news node so probe tests have realistic typed data.
   *
   * Uses NodeInterface::save() so computed values, defaults and constraints
   * have been applied before we ask for normalization.
   *
   * @return \Drupal\node\NodeInterface
   *   The persisted node.
   */
  private function buildOeNewsNode() {
    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Probe title',
      'field_body' => [
        'value' => '<p>Probe body</p>',
        'summary' => 'Probe summary',
        'format' => 'plain_text',
      ],
      'field_teaser' => 'Probe teaser.',
      'field_news_type' => 'announcement',
      'field_publication_date' => '2026-04-17T08:00:00',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Write a normalization probe result and return the path it was written to.
   *
   * @param string $name
   *   File-name slug (no extension).
   * @param mixed $result
   *   The data returned by the normalizer.
   *
   * @return string
   *   Absolute path to the JSON file written.
   */
  private function dumpProbe(string $name, mixed $result): string {
    $path = sys_get_temp_dir() . self::PROBE_DIR . '/' . $name . '.json';
    file_put_contents(
      $path,
      json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    return $path;
  }

  /**
   * Probe: normalize a FieldItemList (e.g. $node->get('title')).
   */
  public function testNormalizeFieldItemList(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $result = $serializer->normalize($node->get('title'), 'json_schema');
    $path = $this->dumpProbe('field-item-list-title', $result);
    $this->assertFileExists($path);
    $this->assertNotEmpty($result, 'Result for FieldItemList normalize() is not empty.');
  }

  /**
   * Probe: normalize a single FieldItem (the .first() of title).
   */
  public function testNormalizeFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $result = $serializer->normalize($node->get('title')->first(), 'json_schema');
    $path = $this->dumpProbe('field-item-title-first', $result);
    $this->assertFileExists($path);
    $this->assertNotEmpty($result, 'Result for FieldItem normalize() is not empty.');
  }

  /**
   * Probe: normalize a single property of a FieldItem (title.value).
   *
   * This is the level at which `PrimitiveDataNormalizer` /
   * `StringData::getCastedValue()` (both of which carry JsonSchema attribute
   * metadata) are expected to take over.
   */
  public function testNormalizeFieldItemProperty(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $result = $serializer->normalize(
      $node->get('title')->first()->get('value'),
      'json_schema'
    );
    $path = $this->dumpProbe('field-item-property-title-value', $result);
    $this->assertFileExists($path);
    $this->assertNotEmpty($result, 'Result for property normalize() is not empty.');
  }

  /**
   * Probe: normalize the body field (formatted text, multi-property).
   */
  public function testNormalizeTextFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $list = $serializer->normalize($node->get('field_body'), 'json_schema');
    $item = $serializer->normalize($node->get('field_body')->first(), 'json_schema');
    $value = $serializer->normalize(
      $node->get('field_body')->first()->get('value'),
      'json_schema'
    );
    $format = $serializer->normalize(
      $node->get('field_body')->first()->get('format'),
      'json_schema'
    );

    $path = $this->dumpProbe('text-field-body', [
      'list' => $list,
      'item' => $item,
      'value_property' => $value,
      'format_property' => $format,
    ]);
    $this->assertFileExists($path);
    $this->assertNotEmpty($value, 'Result for body.value normalize() is not empty.');
  }

  /**
   * Probe: normalize the news type list_string field (with allowed_values).
   */
  public function testNormalizeListStringFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $list = $serializer->normalize($node->get('field_news_type'), 'json_schema');
    $item = $serializer->normalize($node->get('field_news_type')->first(), 'json_schema');
    $value = $serializer->normalize(
      $node->get('field_news_type')->first()->get('value'),
      'json_schema'
    );

    $path = $this->dumpProbe('list-string-field_news_type', [
      'list' => $list,
      'item' => $item,
      'value_property' => $value,
    ]);
    $this->assertFileExists($path);
    $this->assertNotEmpty($value, 'Result for field_news_type.value normalize() is not empty.');
  }

  /**
   * Probe: normalize the publication date field (datetime).
   */
  public function testNormalizeDateTimeFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $list = $serializer->normalize($node->get('field_publication_date'), 'json_schema');
    $item = $serializer->normalize($node->get('field_publication_date')->first(), 'json_schema');
    $value = $serializer->normalize(
      $node->get('field_publication_date')->first()->get('value'),
      'json_schema'
    );

    $path = $this->dumpProbe('datetime-field_publication_date', [
      'list' => $list,
      'item' => $item,
      'value_property' => $value,
    ]);
    $this->assertFileExists($path);
    $this->assertNotEmpty($value, 'Result for field_publication_date.value normalize() is not empty.');
  }

  /**
   * Probe: normalize an entity_reference field (field_contacts).
   */
  public function testNormalizeEntityReferenceFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $list = $serializer->normalize($node->get('field_contacts'), 'json_schema');
    // The field is empty by default; we still want to inspect what the
    // schema looks like, so call ->appendItem() to materialise an item.
    $item_list = $node->get('field_contacts');
    $item_list->appendItem(['target_id' => 1]);
    $item = $serializer->normalize($item_list->first(), 'json_schema');
    $target_id = $serializer->normalize(
      $item_list->first()->get('target_id'),
      'json_schema'
    );

    $path = $this->dumpProbe('entity-reference-field_contacts', [
      'list' => $list,
      'item' => $item,
      'target_id_property' => $target_id,
    ]);
    $this->assertFileExists($path);
    $this->assertNotEmpty($target_id, 'Result for field_contacts.target_id normalize() is not empty.');
  }

  /**
   * Probe: normalize an ER-revisions field (field_content_paragraphs).
   */
  public function testNormalizeEntityReferenceRevisionsFieldItem(): void {
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $list = $serializer->normalize($node->get('field_content_paragraphs'), 'json_schema');
    $item_list = $node->get('field_content_paragraphs');
    // Materialise an item so we can probe the FieldItem level even though no
    // referenced paragraph exists yet.
    $item_list->appendItem(['target_id' => 1, 'target_revision_id' => 1]);
    $item = $serializer->normalize($item_list->first(), 'json_schema');
    $target_id = $serializer->normalize(
      $item_list->first()->get('target_id'),
      'json_schema'
    );

    $path = $this->dumpProbe('er-revisions-field_content_paragraphs', [
      'list' => $list,
      'item' => $item,
      'target_id_property' => $target_id,
    ]);
    $this->assertFileExists($path);
    $this->assertNotEmpty($target_id, 'Result for field_content_paragraphs.target_id normalize() is not empty.');
  }

  /**
   * Probe: alternative path via TypedData definition introspection.
   *
   * The change record at https://www.drupal.org/node/3424710 hints "all core
   * typed data plugins provide JSON Schemas". Investigate whether the
   * definitions themselves expose anything schema-shaped (constraints,
   * settings, allowed values), independent of the normalizer chain.
   */
  public function testTypedDataDefinitionApi(): void {
    $node = $this->buildOeNewsNode();
    $field_definitions_dump = [];

    $field_names = [
      'title',
      'field_body',
      'field_teaser',
      'field_publication_date',
      'field_news_type',
      'field_contacts',
      'field_content_paragraphs',
    ];

    foreach ($field_names as $name) {
      $items = $node->get($name);
      $field_def = $items->getFieldDefinition();
      $storage_def = $field_def->getFieldStorageDefinition();
      $item_def = $items->getItemDefinition();

      $entry = [
        'field_definition' => [
          'class' => $field_def::class,
          'type' => $field_def->getType(),
          'required' => $field_def->isRequired(),
          'cardinality' => $storage_def->getCardinality(),
          'settings' => $field_def->getSettings(),
          'constraint_summary' => array_keys($field_def->getConstraints()),
        ],
        'item_definition' => [
          'class' => $item_def::class,
          'data_type' => $item_def->getDataType(),
          'main_property' => $item_def->getMainPropertyName(),
          'property_definitions' => [],
        ],
      ];

      foreach ($item_def->getPropertyDefinitions() as $prop_name => $prop_def) {
        $entry['item_definition']['property_definitions'][$prop_name] = [
          'class' => $prop_def::class,
          'data_type' => $prop_def->getDataType(),
          'computed' => $prop_def->isComputed(),
          'required' => $prop_def->isRequired(),
          'internal' => $prop_def->isInternal(),
          'constraint_summary' => array_keys($prop_def->getConstraints()),
          'settings' => $prop_def->getSettings(),
        ];
      }

      $field_definitions_dump[$name] = $entry;
    }

    $path = $this->dumpProbe('typed-data-definition', $field_definitions_dump);
    $this->assertFileExists($path);
    $this->assertNotEmpty($field_definitions_dump);
  }

  /**
   * Probe: the higher-level Serializer::getJsonSchema() helper.
   *
   * Confirms the trait's signature (`mixed $object, array $context`) and
   * captures the difference, if any, vs. plain normalize(..., 'json_schema').
   */
  public function testSerializerGetJsonSchemaWithBundleContext(): void {
    /** @var \Drupal\serialization\Serializer\Serializer $serializer */
    $serializer = $this->container->get('serializer');
    $node = $this->buildOeNewsNode();

    $schema = $serializer->getJsonSchema($node, ['bundle' => 'oe_news']);
    $path = $this->dumpProbe('serializer-getjsonschema-bundle-context', $schema);
    $this->assertFileExists($path);
    $this->assertNotEmpty($schema);
  }

  /**
   * AC #4: $serializer->deserialize() round-trips schema-conforming JSON.
   *
   * Scope note: validation only. This test proves core's deserializer
   * accepts the shape. `DraftingPlugin::save()` now uses it for the parent
   * node (with `InlineEntityHydrator` handling inline paragraphs separately,
   * since core 11.3.x drops them silently; see
   * `testDeserializeSilentlyDropsInlineParagraphs`).
   *
   * Note: the JSON shape used here is core's deserialization input shape
   * (matching what `$serializer->normalize($entity, 'json')` would emit),
   * NOT the LLM-facing shape produced by EntityJsonSchemaComposer (which
   * uses x-targetType/x-bundles extension keys and a wrapped envelope).
   * The LLM is expected to emit values matching core's input contract.
   */
  public function testDeserializeFromSchemaConformingJson(): void {
    $serializer = $this->container->get('serializer');

    $json = json_encode([
      'type' => [['target_id' => 'oe_news']],
      'title' => [['value' => 'Deserialisation round-trip']],
      'field_body' => [
        [
          'value' => '<p>Body text</p>',
          'format' => 'plain_text',
        ],
      ],
      'field_teaser' => [['value' => 'Teaser']],
      'field_news_type' => [['value' => 'press_release']],
      'field_publication_date' => [['value' => '2026-04-20']],
    ], JSON_THROW_ON_ERROR);

    /** @var \Drupal\node\NodeInterface $node */
    $node = $serializer->deserialize($json, 'Drupal\node\Entity\Node', 'json');

    $this->assertSame('oe_news', $node->bundle());
    $this->assertSame('Deserialisation round-trip', $node->getTitle());
    $this->assertSame('<p>Body text</p>', $node->get('field_body')->value);
    $this->assertSame('Teaser', $node->get('field_teaser')->value);
    $this->assertSame('press_release', $node->get('field_news_type')->value);
    $this->assertSame('2026-04-20', $node->get('field_publication_date')->value);
  }

  /**
   * Documents core gap: $serializer->deserialize() drops inline paragraphs.
   *
   * Regression alert that fires if/when core lands inline child entity
   * creation (at which point `InlineEntityHydrator` can be retired).
   * Verified against Drupal core 11.3.8: deserialize() succeeds for the
   * parent node and sets scalar fields correctly, but
   * `entity_reference_revisions` items containing inline child entity data
   * are silently dropped: no Paragraph entity is created and the field
   * ends up with zero references.
   *
   * Consequence: `DraftingPlugin::save()` delegates to `InlineEntityHydrator`
   * to pre-create Paragraph entities and rewrite the parent JSON with
   * {target_id, target_revision_id} references before deserializing the
   * parent.
   *
   * REGRESSION ALERT: when this test FAILS (i.e. paragraphs ARE created
   * inline), core has landed the feature and `InlineEntityHydrator` can be
   * retired in favour of a single $serializer->deserialize() call.
   */
  public function testDeserializeSilentlyDropsInlineParagraphs(): void {
    $serializer = $this->container->get('serializer');

    $json = json_encode([
      'type' => [['target_id' => 'oe_news']],
      'title' => [['value' => 'Spike']],
      'field_content_paragraphs' => [
        [
          'type' => [['target_id' => 'text_block']],
          'field_text_body' => [['value' => 'Spike body']],
        ],
      ],
    ], JSON_THROW_ON_ERROR);

    /** @var \Drupal\node\NodeInterface $node */
    $node = $serializer->deserialize($json, 'Drupal\node\Entity\Node', 'json');

    // Parent node deserializes cleanly.
    $this->assertSame('oe_news', $node->bundle());
    $this->assertSame('Spike', $node->getTitle());

    // But the inline paragraph data is silently dropped.
    $paragraphs = $node->get('field_content_paragraphs')->referencedEntities();
    $this->assertCount(0, $paragraphs,
      'Inline paragraph data is silently dropped by core 11.3.8. ' .
      'If this assertion fails, core has landed inline paragraph creation; ' .
      'simplify DraftingPlugin::save() to drop the InlineEntityHydrator delegation.');
  }

}
