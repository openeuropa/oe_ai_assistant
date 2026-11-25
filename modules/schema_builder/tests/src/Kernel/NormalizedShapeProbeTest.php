<?php

declare(strict_types=1);

namespace Drupal\Tests\schema_builder\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests whether a normalized entity can stand in for the field definitions.
 *
 * A schema generator could be fed the array core's serializer produces for an
 * entity, instead of walking the field definitions. The two probes below put
 * the same bundle through both routes and assert what we get out of each.
 */
#[Group('schema_builder')]
class NormalizedShapeProbeTest extends KernelTestBase {

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
    'state_machine',
    'document_loader',
    'document_loader_tika',
    'oe_ai_assistant_test',
    'schema_builder',
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
    $this->installConfig(['system', 'field', 'filter', 'node', 'oe_ai_assistant_test']);
  }

  /**
   * Tests what the normalized array of a populated node contains.
   *
   * A normalized entity is one sample of the data, so we only see the values
   * of the fields that happen to be filled in. The next probe shows what the
   * field definitions add on top of that.
   */
  public function testNormalizedEntityCarriesValuesOnly(): void {
    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Probe title',
      'field_teaser' => 'Probe teaser.',
      'field_news_type' => 'announcement',
    ]);

    $normalized = $this->container->get('serializer')->normalize($node, 'json');

    // The values come back in the shape deserialize() accepts.
    $this->assertSame([['value' => 'Probe title']], $normalized['title']);
    $this->assertSame([['value' => 'announcement']], $normalized['field_news_type']);

    // Once normalized, a list field looks like any other string field. The
    // three allowed values aren't in there, so we can't build an enum.
    $this->assertSame(
      array_keys($normalized['field_news_type'][0]),
      array_keys($normalized['field_teaser'][0]),
    );

    // An empty field normalizes to an empty array, so we learn nothing about
    // the shape of the items it would hold.
    $this->assertSame([], $normalized['field_contacts']);
  }

  /**
   * Tests the constraints the field definitions hold for the same bundle.
   *
   * Each of these decides whether a model's answer is valid, and none of them
   * can be recovered from the normalized array the probe above asserts on.
   */
  public function testFieldDefinitionsCarryTheConstraints(): void {
    $definitions = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('node', 'oe_news');

    // The definitions give us maxLength, and which fields are required.
    $this->assertSame(255, $definitions['title']->getFieldStorageDefinition()->getSetting('max_length'));
    $this->assertTrue($definitions['title']->isRequired());
    $this->assertFalse($definitions['field_teaser']->isRequired());

    // The enum comes from the allowed values on the storage definition.
    $this->assertSame(
      ['press_release', 'announcement', 'update'],
      array_keys($definitions['field_news_type']->getFieldStorageDefinition()->getSetting('allowed_values')),
    );

    // Cardinality gives us maxItems, and -1 means unlimited.
    $this->assertSame(1, $definitions['title']->getFieldStorageDefinition()->getCardinality());
    $this->assertSame(-1, $definitions['field_contacts']->getFieldStorageDefinition()->getCardinality());

    // A reference names its target type and bundles, which is what a nested
    // schema would recurse into.
    $this->assertSame('node', $definitions['field_contacts']->getFieldStorageDefinition()->getSetting('target_type'));
    $this->assertSame(
      ['oe_contact' => 'oe_contact'],
      $definitions['field_contacts']->getSetting('handler_settings')['target_bundles'],
    );
  }

}
