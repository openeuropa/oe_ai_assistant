<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\oe_ai_assistant\Service\DraftAssemblerInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the DraftAssembler service against an existing node.
 *
 * Module list aligns with DraftEntityBuilderTest so the shared
 * `oe_ai_assistant_test` fixture (oe_news) is available.
 */
#[Group('oe_ai_assistant')]
class DraftAssemblerTest extends KernelTestBase {

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
    'ai_agents',
    'oe_ai_assistant',
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
    $this->installConfig([
      'system',
      'field',
      'filter',
      'node',
      'oe_ai_assistant_test',
    ]);
  }

  /**
   * Keeps the existing item's text format when the payload omits it.
   */
  public function testUpdatePreservesExistingTextFormat(): void {
    // The user's default format (lowest weight) differs from the one stored
    // on the node, so a resolver that ignores the existing item shows up as a
    // format change.
    FilterFormat::create(['format' => 'oe_test_basic', 'name' => 'Basic', 'weight' => -10])->save();
    FilterFormat::create(['format' => 'oe_test_rich', 'name' => 'Rich', 'weight' => 0])->save();

    Role::create(['id' => 'oe_test_editor', 'label' => 'Test editor'])
      ->grantPermission('access content')
      ->grantPermission('edit any oe_news content')
      // Content moderation denies update access without a usable transition.
      ->grantPermission('use editorial transition create_new_draft')
      ->grantPermission('use text format oe_test_basic')
      ->grantPermission('use text format oe_test_rich')
      ->save();
    // Uid 1 bypasses all permission checks; consume it so the real test user
    // below is subject to the ordinary permission check the test exercises.
    User::create(['name' => 'Uid 1 placeholder'])->save();
    $user = User::create(['name' => 'Editor', 'roles' => ['oe_test_editor']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $node = Node::create([
      'type' => 'oe_news',
      'title' => 'Existing news',
      'field_news_type' => 'announcement',
      'field_body' => ['value' => '<p>Original body.</p>', 'format' => 'oe_test_rich'],
    ]);
    $node->save();

    $assembled = $this->container->get(DraftAssemblerInterface::class)->assemble(
      'oe_news',
      ['field_body' => [['value' => '<p>Rewritten body.</p>']]],
      NULL,
      $node,
    );

    $this->assertSame('<p>Rewritten body.</p>', $assembled->get('field_body')->value);
    $this->assertSame('oe_test_rich', $assembled->get('field_body')->format);
  }

}
