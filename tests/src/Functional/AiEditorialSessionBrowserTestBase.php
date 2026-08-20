<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSession;

/**
 * Base helpers for AI editorial session browser tests.
 */
abstract class AiEditorialSessionBrowserTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'field',
    'key',
    'node',
    'oe_ai_assistant',
    'options',
    'entity_version',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createNodeType('oe_contact', 'Contact');
    $this->createNodeType('oe_news', 'News');
    $this->installEntityVersionField('oe_news');
    $this->createEditorialContextTerms();
    $this->createDefaultTemplate();
  }

  /**
   * Installs the version field on the given node bundle.
   */
  protected function installEntityVersionField(string $bundle): void {
    \Drupal::service('entity_version.entity_version_installer')
      ->install('node', [$bundle], [
        'major' => 0,
        'minor' => 1,
        'patch' => 0,
      ]);
  }

  /**
   * Creates the default drafting template for the oe_news content type.
   */
  protected function createDefaultTemplate(): void {
    $this->container->get('entity_type.manager')
      ->getStorage('ai_drafting_template')
      ->create([
        'id' => 'news_default',
        'label' => 'News (default)',
        'content_type' => 'oe_news',
        'fields' => ['title' => ['prompt' => 'Headline.']],
      ])->save();
  }

  /**
   * Creates a node type for the tests.
   */
  protected function createNodeType(string $type, string $label): void {
    NodeType::create([
      'type' => $type,
      'name' => $label,
    ])->save();
  }

  /**
   * Creates editorial taxonomy terms used by page bootstrap assertions.
   */
  protected function createEditorialContextTerms(): void {
    Term::create([
      'vid' => 'oe_ai_tone',
      'name' => 'Formal',
      'description' => [
        'value' => 'A professional and neutral tone suitable for official or institutional communication.',
        'format' => 'plain_text',
      ],
      'field_oe_ai_prompt' => 'Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
    ])->save();
  }

  /**
   * Creates a session for the given owner.
   */
  protected function createSession(UserInterface $owner, ?NodeInterface $node = NULL): AiEditorialSession {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = $this->container->get('entity_type.manager')
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
      ]);
    $session->save();

    return $session;
  }

  /**
   * Creates a published node for dashboard coverage.
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
