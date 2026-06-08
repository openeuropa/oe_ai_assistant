<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting\DraftingPromptBuilder;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests DraftingPromptBuilder on EntityJsonSchemaComposer as schema source.
 *
 * The prompt builder's public surface (buildSystemPrompt / buildToolMetadata
 * / buildFieldIndex) is unchanged but the schema payload it inlines is now a
 * real JSON Schema document with a flat properties map. buildFieldIndex()
 * reads $schema['properties'] keys directly instead of walking a grouped
 * form-display tree.
 *
 * Integration test: exercises the real EntityJsonSchemaComposer via the
 * service container. Builder-only assertions are limited to the prompt
 * envelope (heredoc text scaffolding, schema concatenation, empty-bundle
 * guards). Field-name expectations come from the oe_ai_assistant_test
 * fixture; if the fixture changes, this test changes too.
 */
#[Group('oe_ai_assistant')]
class DraftingPromptBuilderTest extends KernelTestBase {

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
   * Returns a fresh DraftingPromptBuilder bound to the composer service.
   */
  private function builder(): DraftingPromptBuilder {
    return new DraftingPromptBuilder(
      $this->container->get(EntityJsonSchemaComposer::class),
      new NullLogger(),
    );
  }

  /**
   * Asserts the system prompt inlines the composer's JSON Schema payload.
   */
  public function testBuildSystemPromptInlinesComposedSchema(): void {
    $prompt = $this->builder()->buildSystemPrompt('node', 'oe_news');

    $this->assertStringContainsString('draft_content tool', $prompt);
    $this->assertStringContainsString("Content type schema:\n", $prompt);
    $this->assertStringContainsString(
      '"title": [{"value":',
      $prompt,
      'The prompt must teach the denormalize-input shape via a literal example; ' .
      'without it, the LLM would have to infer shape from the schema alone.',
    );

    // Decode the JSON tail and assert structurally - substring matching is
    // too permissive (a stub schema would pass with just "properties" present).
    $marker = "Content type schema:\n";
    $jsonTail = substr($prompt, strpos($prompt, $marker) + strlen($marker));
    $decoded = Json::decode($jsonTail);
    $this->assertIsArray($decoded);
    $this->assertSame('object', $decoded['type']);
    $this->assertArrayHasKey('properties', $decoded);
    $this->assertArrayHasKey('title', $decoded['properties']);
  }

  /**
   * Asserts the field index is keyed by the composer's properties keys.
   */
  public function testBuildFieldIndexFromProperties(): void {
    $index = $this->builder()->buildFieldIndex('node', 'oe_news');
    $this->assertArrayHasKey('title', $index);
    $this->assertArrayHasKey('body', $index);
    $this->assertArrayHasKey('field_teaser', $index);
  }

  /**
   * Asserts an empty bundle yields a bare prompt with no schema section.
   */
  public function testEmptyBundleReturnsBareSystemPrompt(): void {
    $prompt = $this->builder()->buildSystemPrompt('node', '');
    $this->assertStringNotContainsString('Content type schema:', $prompt);
  }

  /**
   * Asserts an empty bundle yields an empty field index.
   */
  public function testEmptyBundleReturnsEmptyFieldIndex(): void {
    $this->assertSame([], $this->builder()->buildFieldIndex('node', ''));
  }

  /**
   * Asserts a composer exception yields an empty field index (throw path).
   */
  public function testInvalidEntityTypeReturnsEmptyFieldIndex(): void {
    // Composer throws \InvalidArgumentException for non-content entity types,
    // and the entity type manager throws PluginNotFoundException for unknown
    // entity type ids. Either way buildFieldIndex must swallow and return [].
    $index = $this->builder()->buildFieldIndex('nonexistent_entity_type', 'any');
    $this->assertSame([], $index);
  }

}
