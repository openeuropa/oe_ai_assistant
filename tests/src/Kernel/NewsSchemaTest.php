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
   * Tests the schema of the oe_news bundle.
   */
  public function testNewsSchema(): void {
    $schema = $this->container
      ->get('Drupal\schema_builder\SchemaBuilderInterface')
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
   * Builds the index of a template the test module installs.
   */
  private function index(string $id): TemplateIndex {
    $template = $this->container
      ->get('entity_type.manager')
      ->getStorage('ai_drafting_template')
      ->load($id);

    return new TemplateIndex(
      $this->container->get('Drupal\oe_ai_assistant\Service\TemplateDefaultsResolverInterface'),
      $template,
    );
  }

  /**
   * Tests the schema a drafting template narrows the bundle down to.
   *
   * The news_with_paragraphs template asks for the title, the teaser and
   * three paragraph slots across two bundles, and pins the language.
   * Anything it doesn't name is dropped, at the top level and inside each
   * paragraph.
   */
  public function testTemplateNarrowsTheSchema(): void {
    $schema = $this->container
      ->get('Drupal\schema_builder\SchemaBuilderInterface')
      ->get('node', 'oe_news', ['template' => $this->index('news_with_paragraphs')]);

    $path = sys_get_temp_dir() . '/oe_news.template.schema.json';
    file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->assertSame([
      'langcode',
      'type',
      'title',
      'field_content_paragraphs',
      'field_teaser',
    ], array_keys($schema['properties']));

    // The pinned language is in the schema as a const, not merged into the
    // answer once the model has sent it.
    $this->assertSame(
      'en',
      $schema['properties']['langcode']['items']['properties']['value']['const'],
    );

    // Each paragraph type carries only the fields the template names for it.
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

  /**
   * Tests that a value pinned on a paragraph reaches the nested schema.
   *
   * The default sits on a reference item, so it only describes a bundle the
   * walk reaches by recursing into the paragraph field.
   */
  public function testTemplatePinsNestedValue(): void {
    $schema = $this->container
      ->get('Drupal\schema_builder\SchemaBuilderInterface')
      ->get('node', 'oe_news', ['template' => $this->index('news_nested_defaults')]);

    $quote = $schema['properties']['field_content_paragraphs']['items']['oneOf'][0];

    $this->assertSame(
      'European Commission',
      $quote['properties']['field_quote_attribution']['items']['properties']['value']['const'],
    );

    // The other field of the paragraph is described as usual, so the model
    // still writes it.
    $this->assertArrayNotHasKey(
      'const',
      $quote['properties']['field_quote_text']['items']['properties']['value'],
    );
  }

}
