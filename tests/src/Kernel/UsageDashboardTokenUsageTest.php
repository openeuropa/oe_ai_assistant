<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\oe_ai_assistant\Service\UsageDashboardTokenUsage;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the usage dashboard token usage service.
 */
#[Group('oe_ai_assistant')]
class UsageDashboardTokenUsageTest extends AiEditorialSessionKernelTestBase {

  /**
   * Tests tokens summed per content type for a date range.
   */
  public function testGetTokensUsedByContentType(): void {
    $user = $this->createUser();

    // Two news nodes and one contact node, each with provenance in range.
    $news1 = $this->createNode('oe_news', $user);
    $news2 = $this->createNode('oe_news', $user);
    $contact = $this->createNode('oe_contact', $user);
    // A separate node whose only record falls outside the range.
    $stale = $this->createNode('oe_news', $user);

    // Mid-range timestamp: 2026-06-15 UTC.
    $inRange = (new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC')))->getTimestamp();
    // Out-of-range timestamp: 2026-01-01 UTC.
    $outOfRange = (new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')))->getTimestamp();

    // News totals in range: 1000 + 500 = 1500; contact in range: 2000.
    $this->createRecord($news1, 1000, $inRange);
    $this->createRecord($news2, 500, $inRange);
    $this->createRecord($contact, 2000, $inRange);
    // This record is outside the range and must be ignored.
    $this->createRecord($stale, 9999, $outOfRange);

    $result = $this->service()->getTokensUsedByContentType('2026-06-01', '2026-06-30');

    // Sorted descending: contact (2000) before news (1500).
    $this->assertSame(['oe_contact' => 2000, 'oe_news' => 1500], $result);
  }

  /**
   * Tests that an empty range yields an empty result.
   */
  public function testGetTokensUsedByContentTypeEmpty(): void {
    $this->assertSame([], $this->service()->getTokensUsedByContentType('2026-06-01', '2026-06-30'));
  }

  /**
   * Tests tokens summed per author user for a date range.
   */
  public function testGetTokensUsedByUser(): void {
    $userA = $this->createUser();
    $userB = $this->createUser();

    $nodeA1 = $this->createNode('oe_news', $userA);
    $nodeA2 = $this->createNode('oe_news', $userA);
    $nodeB = $this->createNode('oe_contact', $userB);
    $stale = $this->createNode('oe_news', $userA);

    $inRange = (new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC')))->getTimestamp();
    $outOfRange = (new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')))->getTimestamp();

    // User A in range: 300 + 200 = 500; user B in range: 900.
    $this->createRecordAs($nodeA1, $userA, 300, $inRange);
    $this->createRecordAs($nodeA2, $userA, 200, $inRange);
    $this->createRecordAs($nodeB, $userB, 900, $inRange);
    // Out of range, must be ignored.
    $this->createRecordAs($stale, $userA, 9999, $outOfRange);

    $result = $this->service()->getTokensUsedByUser('2026-06-01', '2026-06-30');

    // Sorted descending: user B (900) before user A (500).
    $this->assertSame([
      (int) $userB->id() => 900,
      (int) $userA->id() => 500,
    ], $result);
  }

  /**
   * Tests that an empty range yields an empty user result.
   */
  public function testGetTokensUsedByUserEmpty(): void {
    $this->assertSame([], $this->service()->getTokensUsedByUser('2026-06-01', '2026-06-30'));
  }

  /**
   * Tests tokens and session counts summed per template for a date range.
   */
  public function testTokensAndSessionsByTemplate(): void {
    $user = $this->createUser();

    // Two distinct sessions use the news_plain template; one uses news_meta.
    $sessionOne = $this->createSession($user);
    $sessionTwo = $this->createSession($user);
    $sessionThree = $this->createSession($user);

    $inRange = (new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC')))->getTimestamp();
    $outOfRange = (new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')))->getTimestamp();

    // news_plain in range: 400 + 350 = 750 across two sessions.
    $this->createTemplateRecord($user, 'news_plain', (int) $sessionOne->id(), 400, $inRange);
    $this->createTemplateRecord($user, 'news_plain', (int) $sessionTwo->id(), 350, $inRange);
    // news_meta in range: 900 in a single session.
    $this->createTemplateRecord($user, 'news_meta', (int) $sessionThree->id(), 900, $inRange);
    // Out of range, must be ignored by both methods.
    $this->createTemplateRecord($user, 'news_plain', (int) $sessionOne->id(), 9999, $outOfRange);

    $tokens = $this->service()->getTokensUsedByTemplate('2026-06-01', '2026-06-30');
    // Sorted descending: news_meta (900) before news_plain (750).
    $this->assertSame(['news_meta' => 900, 'news_plain' => 750], $tokens);

    $sessions = $this->service()->countSessionsByTemplate('2026-06-01', '2026-06-30');
    $this->assertSame(2, $sessions['news_plain']);
    $this->assertSame(1, $sessions['news_meta']);
  }

  /**
   * Tests that an empty range yields empty template results.
   */
  public function testTemplateMethodsEmpty(): void {
    $this->assertSame([], $this->service()->getTokensUsedByTemplate('2026-06-01', '2026-06-30'));
    $this->assertSame([], $this->service()->countSessionsByTemplate('2026-06-01', '2026-06-30'));
  }

  /**
   * Creates a node of the given type.
   */
  private function createNode(string $type, UserInterface $user): NodeInterface {
    $node = Node::create([
      'type' => $type,
      'title' => 'Node ' . $type . ' ' . ++$this->testEntityCounter,
      'uid' => $user->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a provenance record for a node with a fixed token total and time.
   */
  private function createRecord(NodeInterface $node, int $tokensTotal, int $created): void {
    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'uid' => $node->getOwnerId(),
      'tokens_input' => 0,
      'tokens_output' => 0,
      'tokens_total' => $tokensTotal,
      'provider' => 'mock',
      'model' => 'mock-model',
      'created' => $created,
    ])->save();
  }

  /**
   * Creates a provenance record attributed to a specific author.
   */
  private function createRecordAs(NodeInterface $node, UserInterface $user, int $tokensTotal, int $created): void {
    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'uid' => $user->id(),
      'tokens_input' => 0,
      'tokens_output' => 0,
      'tokens_total' => $tokensTotal,
      'provider' => 'mock',
      'model' => 'mock-model',
      'created' => $created,
    ])->save();
  }

  /**
   * Creates a provenance record referencing a template and session.
   */
  private function createTemplateRecord(UserInterface $user, string $templateId, int $sessionId, int $tokensTotal, int $created): void {
    // Each record targets a fresh node revision to satisfy the unique
    // (entity_type, entity_id, revision_id) key.
    $node = $this->createNode('oe_news', $user);
    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'uid' => $user->id(),
      'session' => $sessionId,
      'template' => $templateId,
      'tokens_input' => 0,
      'tokens_output' => 0,
      'tokens_total' => $tokensTotal,
      'provider' => 'mock',
      'model' => 'mock-model',
      'created' => $created,
    ])->save();
  }

  /**
   * Returns the token usage service.
   */
  private function service(): UsageDashboardTokenUsage {
    return $this->container->get(UsageDashboardTokenUsage::class);
  }

}
