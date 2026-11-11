<?php

declare(strict_types=1);

namespace Drupal\Tests\schema_api\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the JSON Schema SchemaApi builds for a bundle.
 */
#[Group('schema_api')]
class SchemaApiTest extends KernelTestBase {

  use UserCreationTrait;

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
    'schema_api_test',
  ];

  /**
   * The configured fields of oe_news, in the order the assertion expects them.
   */
  private const FIELDS = [
    'title',
    'field_body',
    'field_news_type',
    'field_publication_date',
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
   * Tests the schema of the configured fields of oe_news.
   *
   * The expectation is written out in full so you can read the shape of the
   * document here. It leaves out the base fields of a node, which would add
   * two dozen more properties and exercise none of the rules under test.
   */
  public function testSchemaOfTheConfiguredFields(): void {
    $expected = <<<'JSON'
    {
        "type": "object",
        "properties": {
            "title": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "value": {
                            "type": "string",
                            "maxLength": 255
                        }
                    },
                    "additionalProperties": false,
                    "required": [
                        "value"
                    ]
                },
                "maxItems": 1,
                "minItems": 1,
                "description": "Title"
            },
            "field_body": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "value": {
                            "type": "string"
                        },
                        "format": {
                            "type": "string",
                            "enum": [
                                "plain_text"
                            ]
                        },
                        "summary": {
                            "type": "string"
                        }
                    },
                    "additionalProperties": false,
                    "required": [
                        "value"
                    ]
                },
                "maxItems": 1,
                "description": "Body"
            },
            "field_news_type": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "value": {
                            "type": "string",
                            "maxLength": 255,
                            "enum": [
                                "press_release",
                                "announcement",
                                "update"
                            ]
                        }
                    },
                    "additionalProperties": false,
                    "required": [
                        "value"
                    ]
                },
                "maxItems": 1,
                "description": "News type"
            },
            "field_publication_date": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "value": {
                            "type": "string",
                            "format": "date"
                        }
                    },
                    "additionalProperties": false,
                    "required": [
                        "value"
                    ]
                },
                "maxItems": 1,
                "description": "Publication date"
            }
        },
        "additionalProperties": false,
        "required": [
            "type",
            "title"
        ]
    }
    JSON;

    $schema = $this->container->get('Drupal\schema_api\SchemaApiInterface')->get('node', 'oe_news');

    $properties = [];
    foreach (self::FIELDS as $name) {
      $properties[$name] = $schema['properties'][$name];
    }

    $actual = json_encode([
      'type' => $schema['type'],
      'properties' => $properties,
      'additionalProperties' => $schema['additionalProperties'],
      'required' => $schema['required'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $this->assertSame($expected, $actual);
  }

  /**
   * Tests that the text formats offered follow the permissions of the user.
   *
   * The formats a user may write in differ from one account to the next, so
   * no field metadata can state them. This is the case FieldItemSchemaEvent
   * exists for.
   */
  public function testTextFormatsFollowTheAccount(): void {
    $this->installSchema('user', ['users_data']);

    FilterFormat::create([
      'format' => 'restricted_html',
      'name' => 'Restricted HTML',
      'weight' => 1,
    ])->save();

    $before = $this->schema()['properties']['field_body']['items']['properties']['format']['enum'];
    $this->assertSame(['plain_text'], $before);

    $this->setUpCurrentUser([], ['use text format restricted_html']);

    $after = $this->schema()['properties']['field_body']['items']['properties']['format']['enum'];
    $this->assertContains('restricted_html', $after);
  }

  /**
   * Returns the schema of the oe_news bundle.
   */
  private function schema(): array {
    return $this->container->get('Drupal\schema_api\SchemaApiInterface')->get('node', 'oe_news');
  }

  /**
   * Tests that an email field is described with the email format.
   *
   * The Email constraint comes with the email data type, so this covers the
   * path from a constraint on the property definition to a schema keyword.
   */
  public function testEmailFieldFormat(): void {
    $this->createField('field_author_mail', 'email', 'Author mail');

    $schema = $this->schema();

    $this->assertSame(
      'email',
      $schema['properties']['field_author_mail']['items']['properties']['value']['format'],
    );
  }

  /**
   * Tests that a module can add a keyword SchemaApi knows nothing about.
   *
   * The schema_api_test module subscribes to PropertySchemaEvent and sets
   * the format of the uri property of a link field. Nothing in schema_api
   * mentions link fields.
   */
  public function testSubscriberAddsItsOwnKeyword(): void {
    $this->createField('field_source', 'link', 'Source');

    $schema = $this->schema();

    $this->assertSame(
      'uri',
      $schema['properties']['field_source']['items']['properties']['uri']['format'],
    );
  }

  /**
   * Creates a field on the oe_news bundle.
   */
  private function createField(string $name, string $type, string $label, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'oe_news',
      'label' => $label,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Tests that the min and max of a numeric field end up in the schema.
   *
   * NumericItemBase declares its Range in a ComplexData constraint on the
   * field item, so none of it is visible on the property definition. This
   * is the case itemConstraints() exists for.
   */
  public function testNumericFieldBounds(): void {
    $this->createField('field_rating', 'integer', 'Rating', ['min' => 1, 'max' => 5]);

    $schema = $this->container->get('Drupal\schema_api\SchemaApiInterface')->get('node', 'oe_news');

    $this->assertSame([
      'type' => 'integer',
      'minimum' => 1,
      'maximum' => 5,
    ], $schema['properties']['field_rating']['items']['properties']['value']);
  }

}
