<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
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
    'oe_ai_assistant_test',
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

    $this->configureSummaryTestProvider();
  }

  /**
   * Configures a deterministic multimodal provider for document fixtures.
   */
  protected function configureSummaryTestProvider(): void {
    $this->config('ai.settings')
      ->set('default_providers.chat_with_image_vision', [
        'provider_id' => 'summary_test',
        'model_id' => 'summary-test-model',
      ])
      ->save();
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
      'moderation_state' => 'published',
    ]);
    $node->save();

    return $node;
  }

}
