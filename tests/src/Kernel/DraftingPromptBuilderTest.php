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
 * Tests DraftingPromptBuilder against the EntityJsonSchemaComposer.
 *
 * Task 6 of the OEL-4691 Path A plan: the prompt builder's schema source
 * is the new composer service. The builder's public surface is unchanged
 * (buildSystemPrompt / buildToolMetadata / buildFieldIndex) but the schema
 * payload it inlines is now a real JSON Schema document with a flat
 * properties map - hence buildFieldIndex() now reads $schema['properties']
 * keys directly instead of walking a grouped form-display tree.
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
