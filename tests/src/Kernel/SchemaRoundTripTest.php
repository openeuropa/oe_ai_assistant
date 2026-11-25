<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\AiDraftingTemplateInterface;
use Drupal\oe_ai_assistant\SchemaBuilder\TemplateIndex;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that an answer the schema accepts can be turned into an entity.
 *
 * The schema is a promise: anything matching it can be saved. Checking the
 * two halves apart leaves room for a schema that asks for a value the save
 * path refuses, which a reader of either half cannot see. So this builds a
 * payload from the schema itself, validates it, and saves what comes out.
 */
#[Group('oe_ai_assistant')]
class SchemaRoundTripTest extends KernelTestBase {

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
    'schema_builder',
    'state_machine',
    'document_loader',
    'document_loader_tika',
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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'filter', 'node', 'oe_ai_assistant_test']);
  }

  /**
   * Tests that a news draft built from its own schema saves.
   */
  public function testNewsDraftSaves(): void {
    $this->assertRoundTrip('news_with_paragraphs');
  }

  /**
   * Tests that a draft of a template pinning a nested value saves.
   */
  public function testNestedDefaultsDraftSaves(): void {
    $this->assertRoundTrip('news_nested_defaults');
  }

  /**
   * Tests that a draft of a field with a serialized column saves.
   *
   * A link field keeps its options in a serialized column, which the
   * denormalizer refuses to read from a string.
   */
  public function testLinkFieldDraftSaves(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_drafting_template');
    $template = $storage->load('news_default');
    $template->set('fields', ['title' => ['prompt' => 'Headline.'], 'field_news_link' => ['prompt' => 'A link.']]);

    $this->assertRoundTrip($template);
  }

  /**
   * Builds a payload from a template's schema, validates it, and saves it.
   */
  private function assertRoundTrip(AiDraftingTemplateInterface|string $template): void {
    if (is_string($template)) {
      $template = $this->container->get('entity_type.manager')
        ->getStorage('ai_drafting_template')
        ->load($template);
    }
    $index = new TemplateIndex(
      $this->container->get('Drupal\oe_ai_assistant\Service\TemplateDefaultsResolverInterface'),
      $template,
    );
    $schema = $this->container->get('Drupal\schema_builder\SchemaBuilderInterface')
      ->get('node', 'oe_news', ['template' => $index]);

    $payload = $this->payload($schema);

    // The answer has to match the schema a drafter is given, or the rest of
    // the test proves nothing about what a model may send.
    // The validator takes its subject by reference, so it is a variable.
    $subject = json_decode(json_encode($payload), FALSE);
    $validator = new Validator();
    $validator->validate($subject, json_decode(json_encode($schema), FALSE));
    $this->assertSame([], $validator->getErrors());

    $node = $this->container->get('Drupal\oe_ai_assistant\Service\DraftEntityBuilder')
      ->fromLlmFields('node', 'oe_news', $payload);
    $node->save();

    $this->assertFalse($node->isNew(), 'The draft built from the schema saved.');
  }

  /**
   * Builds a payload filling everything an object schema offers.
   *
   * Every key is filled, not only the required ones. A schema that offers a
   * key is telling a model it may send that key, so a key that cannot be
   * saved is a defect whether or not anything demands it.
   */
  private function payload(array $schema): array {
    $payload = [];
    foreach ($schema['properties'] ?? [] as $name => $property) {
      $payload[$name] = $this->value($property);
    }

    return $payload;
  }

  /**
   * Builds a value for one field, item or property schema.
   */
  private function value(array $schema): mixed {
    if (isset($schema['const'])) {
      return $schema['const'];
    }
    if (isset($schema['enum'])) {
      return $schema['enum'][array_key_first($schema['enum'])];
    }

    // A oneOf offers a choice of bundles, and the first is as good as any.
    if (isset($schema['oneOf'])) {
      return $this->payload($schema['oneOf'][0]);
    }

    return match ($schema['type'] ?? 'string') {
      'array' => [$this->value($schema['items'])],
      'object' => $this->payload($schema),
      'boolean' => TRUE,
      'integer' => 1,
      'number' => 1.0,
      default => $this->text($schema),
    };
  }

  /**
   * Builds a string that respects the format and length a schema asks for.
   */
  private function text(array $schema): string {
    $value = match ($schema['format'] ?? NULL) {
      'date' => '2026-11-12',
      'date-time' => '2026-11-12T09:00:00',
      'email' => 'editor@example.com',
      'uri' => 'https://example.com',
      'uuid' => '0d2b0e3a-5f4c-4a6e-9a1b-2c3d4e5f6a7b',
      default => 'Text',
    };

    return isset($schema['maxLength'])
      ? substr($value, 0, (int) $schema['maxLength'])
      : $value;
  }

}
