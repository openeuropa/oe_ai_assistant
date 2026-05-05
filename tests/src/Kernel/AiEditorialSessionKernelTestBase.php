<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Base helpers for AI editorial session kernel tests.
 */
abstract class AiEditorialSessionKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'content_moderation',
    'field',
    'node',
    'oe_ai_assistant',
    'options',
    'system',
    'user',
    'key',
    'workflows',
  ];

  /**
   * A counter used to generate unique test users and roles.
   */
  protected int $testEntityCounter = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('ai_editorial_session');

    $this->installConfig(['system', 'user', 'node', 'oe_ai_assistant']);

    $this->createContentType('oe_contact', 'Contact');
    $this->createContentType('oe_news', 'News');

    // Reserve UID 1 so test users do not bypass access checks as superuser.
    $this->createUser();
  }

  /**
   * Creates a node type for the tests.
   */
  protected function createContentType(string $type, string $label): void {
    NodeType::create([
      'type' => $type,
      'name' => $label,
    ])->save();
  }

  /**
   * Creates a user with the given permissions.
   */
  protected function createUser(array $permissions = []): UserInterface {
    $suffix = (string) ++$this->testEntityCounter;

    $user = User::create([
      'name' => 'test-user-' . $suffix,
      'mail' => 'test-user-' . $suffix . '@example.com',
      'status' => 1,
    ]);

    if ($permissions !== []) {
      $role = Role::create([
        'id' => 'test_role_' . $suffix,
        'label' => 'Test role ' . $suffix,
      ]);
      foreach ($permissions as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $user->addRole($role->id());
    }

    $user->save();
    return $user;
  }

  /**
   * Creates a session for the given owner.
   */
  protected function createSession(UserInterface $owner, ?NodeInterface $node = NULL): \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'bundle' => 'drafting',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
        'template_id' => 'landing_page',
        'node_id' => $node?->id(),
      ]);
    $session->save();

    return $session;
  }

  /**
   * Creates a published node for collaboration tests.
   */
  protected function createPublishedNode(string $type, string $title): NodeInterface {
    $node = Node::create([
      'type' => $type,
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();

    return $node;
  }

}
