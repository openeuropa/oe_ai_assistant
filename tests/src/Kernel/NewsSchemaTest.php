<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\SchemaBuilder\TemplateIndex;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the whole schema this module asks for when drafting a news item.
 *
 * The expectation is written out in full, nested paragraphs included, so the
 * document a model would be handed can be read here.
 */
#[Group('oe_ai_assistant')]
class NewsSchemaTest extends KernelTestBase {

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
    'schema_api',
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
   * Tests the schema of the oe_news bundle.
   */
  public function testNewsSchema(): void {
    $schema = $this->container
      ->get('Drupal\schema_api\SchemaApiInterface')
      ->get('node', 'oe_news');

    $path = sys_get_temp_dir() . '/oe_news.schema.json';
    file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Only the title, the bundle and the configured fields are asked for.
    $this->assertSame([
      'langcode',
      'type',
      'title',
      'moderation_state',
      'field_body',
      'field_contacts',
      'field_content_paragraphs',
      'field_keywords',
      'field_news_image',
      'field_news_link',
      'field_news_tags',
      'field_news_type',
      'field_publication_date',
      'field_publication_datetime',
      'field_teaser',
    ], array_keys($schema['properties']));

    // The values this module pins are stated rather than applied later, so
    // the document a model returns is validated with them in it.
    $this->assertSame(
      'en',
      $schema['properties']['langcode']['items']['properties']['value']['const'],
    );
    $this->assertSame(
      'draft',
      $schema['properties']['moderation_state']['items']['properties']['value']['const'],
    );

    // The bundle names itself, so a reader can tell the variants apart.
    $this->assertSame(
      'oe_news',
      $schema['properties']['type']['items']['properties']['target_id']['const'],
    );

    // A paragraph field offers a choice of whole paragraphs.
    $paragraphs = $schema['properties']['field_content_paragraphs']['items'];
    $this->assertSame('paragraph', $paragraphs['x-targetType']);
    $this->assertNotEmpty($paragraphs['oneOf']);

    $this->assertFileExists($path);
  }

  /**
   * Tests the schema a drafting template narrows the bundle down to.
   *
   * The template names four fields and three paragraph slots across two
   * bundles, and pins the language. Everything it does not name is left out,
   * at the top level and inside each paragraph.
   */
  public function testTemplateNarrowsTheSchema(): void {
    $template = $this->container
      ->get('entity_type.manager')
      ->getStorage('ai_drafting_template')
      ->load('news_with_paragraphs');

    $schema = $this->container
      ->get('Drupal\schema_api\SchemaApiInterface')
      ->get('node', 'oe_news', ['template' => new TemplateIndex($template)]);

    $path = sys_get_temp_dir() . '/oe_news.template.schema.json';
    file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->assertSame([
      'langcode',
      'type',
      'title',
      'field_content_paragraphs',
      'field_teaser',
    ], array_keys($schema['properties']));

    // The pinned language is stated rather than applied after the answer.
    $this->assertSame(
      'en',
      $schema['properties']['langcode']['items']['properties']['value']['const'],
    );

    // Each paragraph carries only the fields its slot names.
    $variants = $schema['properties']['field_content_paragraphs']['items']['oneOf'];
    $this->assertSame(
      [
        ['type', 'field_text_body'],
        ['type', 'field_quote_attribution', 'field_quote_text'],
      ],
      array_map(static fn (array $variant) => array_keys($variant['properties']), $variants),
    );

    // Each variant still names its own bundle, so they can be told apart.
    $this->assertSame(
      ['text_block', 'quote_block'],
      array_map(
        static fn (array $variant) => $variant['properties']['type']['items']['properties']['target_id']['const'],
        $variants,
      ),
    );

    $this->assertFileExists($path);
  }

}
