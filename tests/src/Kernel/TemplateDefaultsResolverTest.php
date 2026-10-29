<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Service\TemplateDefaultsResolverInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests token and entity reference resolution of template defaults.
 */
#[Group('oe_ai_assistant')]
class TemplateDefaultsResolverTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter', 'oe_ai_assistant_test']);
  }

  /**
   * Returns the resolver under test.
   */
  private function resolver(): TemplateDefaultsResolverInterface {
    return $this->container->get(TemplateDefaultsResolverInterface::class);
  }

  /**
   * Tests that __NOW__ is replaced with the current Unix timestamp.
   */
  public function testNowTokenIsReplaced(): void {
    $expectedTime = $this->container->get('datetime.time')->getRequestTime();

    $resolved = $this->resolver()->resolve([
      'created' => [
        'default_value' => [['value' => '__NOW__']],
      ],
      'langcode' => [
        'default_value' => [['value' => 'en']],
      ],
    ], 'node', 'oe_news');

    $this->assertSame($expectedTime, $resolved['created']['default_value'][0]['value']);
    $this->assertSame('en', $resolved['langcode']['default_value'][0]['value']);
  }

  /**
   * Tests that defaults without tokens or references are returned unchanged.
   */
  public function testNoTokensIsPassthrough(): void {
    $defaults = [
      'langcode' => [
        'default_value' => [['value' => 'en']],
      ],
    ];

    $this->assertSame($defaults, $this->resolver()->resolve($defaults, 'node', 'oe_news'));
  }

  /**
   * Tests that a target_uuid on a node field resolves to the target_id.
   */
  public function testResolvesNodeTargetUuid(): void {
    $contact = $this->createContactNode();

    $resolved = $this->resolver()->resolve([
      'field_contacts' => [
        'default_value' => [
          ['target_uuid' => $contact->uuid()],
        ],
      ],
    ], 'node', 'oe_news');

    $this->assertSame(
      (int) $contact->id(),
      $resolved['field_contacts']['default_value'][0]['target_id']
    );
    $this->assertArrayNotHasKey('target_uuid', $resolved['field_contacts']['default_value'][0]);
  }

  /**
   * Tests that a target_uuid resolves for a bundle other than the node's.
   */
  public function testResolvesTargetUuidForAnyBundle(): void {
    $manager = $this->createContactNode('Contact Manager');

    $resolved = $this->resolver()->resolve([
      'field_contact_manager' => [
        'default_value' => [
          ['target_uuid' => $manager->uuid()],
        ],
      ],
    ], 'node', 'oe_contact');

    $this->assertSame(
      (int) $manager->id(),
      $resolved['field_contact_manager']['default_value'][0]['target_id']
    );
  }

  /**
   * Tests that target_id values pass through untouched.
   */
  public function testTargetIdPassesThrough(): void {
    $defaults = [
      'field_contact_manager' => [
        'default_value' => [
          ['target_id' => 42],
        ],
      ],
    ];

    $this->assertSame($defaults, $this->resolver()->resolve($defaults, 'node', 'oe_contact'));
  }

  /**
   * Creates and saves an oe_contact node to reference by uuid.
   */
  private function createContactNode(string $name = 'Jane Doe'): NodeInterface {
    $node = Node::create([
      'type' => 'oe_contact',
      'title' => 'Contact node',
      'field_contact_name' => $name,
    ]);
    $node->save();
    return $node;
  }

}
