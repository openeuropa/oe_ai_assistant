<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\oe_ai_assistant\Service\DashboardFilters;
use Drupal\oe_ai_assistant\Service\UsageDashboardTokenUsage;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for the usage dashboard drill-down row queries.
 */
#[Group('oe_ai_assistant')]
class UsageDashboardDrilldownTest extends AiEditorialSessionKernelTestBase {

  /**
   * The in-range timestamp used by every record unless noted.
   */
  private int $inRange;

  /**
   * An out-of-range timestamp that every query must ignore.
   */
  private int $outOfRange;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->inRange = (new \DateTimeImmutable('2026-06-15', new \DateTimeZone('UTC')))->getTimestamp();
    $this->outOfRange = (new \DateTimeImmutable('2026-01-01', new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * Tests per-node rows with counts, token totals and the template flag.
   */
  public function testGetNodeRows(): void {
    $user = $this->createUser();
    $nodeA = $this->createNode('oe_news', $user);
    $nodeB = $this->createNode('oe_contact', $user);
    $sessionOne = $this->createSession($user);
    $sessionTwo = $this->createSession($user);

    // Node A: two sessions, majors 1 and 2, with a template, 600 tokens total.
    $this->record($nodeA, $user, 300, [
      'session' => (int) $sessionOne->id(),
      'version_major' => 1,
      'template' => 'news_plain',
    ]);
    $this->record($nodeA, $user, 300, [
      'session' => (int) $sessionTwo->id(),
      'version_major' => 2,
      'template' => 'news_plain',
    ]);
    // Node B: one session, major 1, no template, 1000 tokens.
    $this->record($nodeB, $user, 1000, ['session' => (int) $sessionOne->id(), 'version_major' => 1]);
    // Out of range, ignored.
    $this->record($nodeA, $user, 9999, ['created' => $this->outOfRange]);

    $rows = $this->service()->getNodeRows('2026-06-01', '2026-06-30');

    // Sorted by tokens descending: node B (1000) before node A (600).
    $this->assertCount(2, $rows);
    $this->assertSame((int) $nodeB->id(), $rows[0]['node_id']);
    $this->assertSame(1000, $rows[0]['tokens']);
    $this->assertSame(1, $rows[0]['majors']);
    $this->assertSame(1, $rows[0]['sessions']);
    $this->assertFalse($rows[0]['template']);

    $this->assertSame((int) $nodeA->id(), $rows[1]['node_id']);
    $this->assertSame(600, $rows[1]['tokens']);
    $this->assertSame(2, $rows[1]['majors']);
    $this->assertSame(2, $rows[1]['sessions']);
    $this->assertTrue($rows[1]['template']);
    $this->assertSame((int) $user->id(), $rows[1]['uid']);
    $this->assertSame('oe_news', $rows[1]['bundle']);
  }

  /**
   * Tests per-major-version rows with session counts and token totals.
   */
  public function testGetMajorVersionRows(): void {
    $user = $this->createUser();
    $node = $this->createNode('oe_news', $user);
    $sessionOne = $this->createSession($user);
    $sessionTwo = $this->createSession($user);

    // Major 2: two sessions, 900 tokens. Major 1: one session, 200 tokens.
    $this->record($node, $user, 500, ['session' => (int) $sessionOne->id(), 'version_major' => 2]);
    $this->record($node, $user, 400, ['session' => (int) $sessionTwo->id(), 'version_major' => 2]);
    $this->record($node, $user, 200, ['session' => (int) $sessionOne->id(), 'version_major' => 1]);

    $rows = $this->service()->getMajorVersionRows('2026-06-01', '2026-06-30');

    $this->assertCount(2, $rows);
    // Sorted by tokens descending: major 2 (900) before major 1 (200).
    $this->assertSame(2, $rows[0]['major']);
    $this->assertSame(900, $rows[0]['tokens']);
    $this->assertSame(2, $rows[0]['sessions']);
    $this->assertSame(1, $rows[1]['major']);
    $this->assertSame(200, $rows[1]['tokens']);
    $this->assertSame(1, $rows[1]['sessions']);
  }

  /**
   * Tests per-session rows with node, major, token totals and template flag.
   */
  public function testGetSessionRows(): void {
    $user = $this->createUser();
    $node = $this->createNode('oe_news', $user);
    $sessionOne = $this->createSession($user);
    $sessionTwo = $this->createSession($user);

    // Session one: 700 tokens across two records, major 3, template set.
    $this->record($node, $user, 400, [
      'session' => (int) $sessionOne->id(),
      'version_major' => 3,
      'template' => 'news_plain',
    ]);
    $this->record($node, $user, 300, [
      'session' => (int) $sessionOne->id(),
      'version_major' => 3,
      'template' => 'news_plain',
    ]);
    // Session two: 200 tokens, major 1, no template.
    $this->record($node, $user, 200, ['session' => (int) $sessionTwo->id(), 'version_major' => 1]);

    $rows = $this->service()->getSessionRows('2026-06-01', '2026-06-30');

    $this->assertCount(2, $rows);
    // Sorted by tokens descending: session one (700) first.
    $this->assertSame((int) $sessionOne->id(), $rows[0]['session_id']);
    $this->assertSame(700, $rows[0]['tokens']);
    $this->assertSame(3, $rows[0]['major']);
    $this->assertTrue($rows[0]['template']);
    $this->assertSame((int) $node->id(), $rows[0]['node_id']);

    $this->assertSame((int) $sessionTwo->id(), $rows[1]['session_id']);
    $this->assertSame(200, $rows[1]['tokens']);
    $this->assertSame(1, $rows[1]['major']);
    $this->assertFalse($rows[1]['template']);
  }

  /**
   * Tests per-user rows with node, session counts and token totals.
   */
  public function testGetUserRows(): void {
    $userA = $this->createUser();
    $userB = $this->createUser();
    $nodeA1 = $this->createNode('oe_news', $userA);
    $nodeA2 = $this->createNode('oe_news', $userA);
    $nodeB = $this->createNode('oe_contact', $userB);
    $sessionA1 = $this->createSession($userA);
    $sessionA2 = $this->createSession($userA);
    $sessionB = $this->createSession($userB);

    // User A: two nodes, two sessions, 500 tokens.
    $this->record($nodeA1, $userA, 300, ['session' => (int) $sessionA1->id()]);
    $this->record($nodeA2, $userA, 200, ['session' => (int) $sessionA2->id()]);
    // User B: one node, one session, 900 tokens.
    $this->record($nodeB, $userB, 900, ['session' => (int) $sessionB->id()]);

    $rows = $this->service()->getUserRows('2026-06-01', '2026-06-30');

    $this->assertCount(2, $rows);
    // Sorted by tokens descending: user B (900) first.
    $this->assertSame((int) $userB->id(), $rows[0]['uid']);
    $this->assertSame(900, $rows[0]['tokens']);
    $this->assertSame(1, $rows[0]['nodes']);
    $this->assertSame(1, $rows[0]['sessions']);

    $this->assertSame((int) $userA->id(), $rows[1]['uid']);
    $this->assertSame(500, $rows[1]['tokens']);
    $this->assertSame(2, $rows[1]['nodes']);
    $this->assertSame(2, $rows[1]['sessions']);
  }

  /**
   * Tests that the content type filter restricts the node rows.
   */
  public function testNodeRowsContentTypeFilter(): void {
    $user = $this->createUser();
    $news = $this->createNode('oe_news', $user);
    $contact = $this->createNode('oe_contact', $user);
    $this->record($news, $user, 100, []);
    $this->record($contact, $user, 200, []);

    $rows = $this->service()->getNodeRows('2026-06-01', '2026-06-30', new DashboardFilters('oe_news', NULL));

    $this->assertCount(1, $rows);
    $this->assertSame((int) $news->id(), $rows[0]['node_id']);
  }

  /**
   * Tests that empty ranges yield empty drill-down results.
   */
  public function testEmptyRange(): void {
    $this->assertSame([], $this->service()->getNodeRows('2026-06-01', '2026-06-30'));
    $this->assertSame([], $this->service()->getMajorVersionRows('2026-06-01', '2026-06-30'));
    $this->assertSame([], $this->service()->getSessionRows('2026-06-01', '2026-06-30'));
    $this->assertSame([], $this->service()->getUserRows('2026-06-01', '2026-06-30'));
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
   * Creates a provenance record for a node with optional extra fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The tracked node.
   * @param \Drupal\user\UserInterface $user
   *   The author.
   * @param int $tokens
   *   The total tokens.
   * @param array $extra
   *   Extra field values (session, version_major, template, created). Each
   *   record targets a fresh revision to satisfy the unique key.
   */
  private function record(NodeInterface $node, UserInterface $user, int $tokens, array $extra): void {
    // A new revision keeps the (entity_type, entity_id, revision_id) key
    // unique across the several records a test attaches to one node.
    $node->setNewRevision(TRUE);
    $node->save();

    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'uid' => $user->id(),
      'tokens_input' => 0,
      'tokens_output' => 0,
      'tokens_total' => $tokens,
      'provider' => 'mock',
      'model' => 'mock-model',
      'created' => $extra['created'] ?? $this->inRange,
    ] + array_intersect_key($extra, array_flip(['session', 'version_major', 'template'])))->save();
  }

  /**
   * Returns the token usage service.
   */
  private function service(): UsageDashboardTokenUsage {
    return $this->container->get(UsageDashboardTokenUsage::class);
  }

}
