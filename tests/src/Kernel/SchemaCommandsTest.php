<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Drush\Commands\SchemaCommands;
use Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Service\TemplateSchemaFilterInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the input-validation guards of the schema Drush command.
 *
 * Only the error paths are covered: the happy path is thin glue over services
 * that have their own tests, and exercising it needs the Drush output runtime.
 */
#[Group('oe_ai_assistant')]
class SchemaCommandsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    'serialization',
    'image',
    'link',
    'taxonomy',
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    'oe_ai_assistant',
    'oe_ai_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['oe_ai_assistant_test']);
  }

  /**
   * Builds the command with real services.
   */
  private function command(): SchemaCommands {
    return new SchemaCommands(
      $this->container->get(EntityJsonSchemaComposer::class),
      $this->container->get(TemplateSchemaFilterInterface::class),
      $this->container->get(DraftingSchemaProviderInterface::class),
      $this->container->get('entity_type.bundle.info'),
    );
  }

  /**
   * Runs the command with the given bundle and option overrides.
   */
  private function runCommand(string $bundle, array $options = []): void {
    $this->command()->schema($bundle, $options + [
      'entity-type' => 'node',
      'template' => '',
      'groups' => FALSE,
    ]);
  }

  /**
   * An unknown bundle is rejected instead of composing a phantom schema.
   */
  public function testUnknownBundleThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown node bundle "does_not_exist"');
    $this->runCommand('does_not_exist');
  }

  /**
   * An unknown template id is rejected.
   */
  public function testUnknownTemplateThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Drafting template "does_not_exist" not found');
    $this->runCommand('oe_news', ['template' => 'does_not_exist']);
  }

  /**
   * A template built for another content type is rejected.
   */
  public function testContentTypeMismatchThrows(): void {
    // news_default targets oe_news, not oe_contact.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('targets content type "oe_news", not "oe_contact"');
    $this->runCommand('oe_contact', ['template' => 'news_default']);
  }

}
