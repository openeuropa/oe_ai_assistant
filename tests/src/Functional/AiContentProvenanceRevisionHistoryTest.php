<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Tests the AI-assisted marker on the node revision history page.
 *
 * @group oe_ai_assistant
 */
final class AiContentProvenanceRevisionHistoryTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests the marker on tracked revisions and the access-checked session link.
   */
  public function testRevisionHistoryMarker(): void {
    $owner = $this->drupalCreateUser([
      'access content',
      'view all revisions',
      'view_update own sessions',
    ]);
    $session = $this->createSession($owner);
    $node = $this->createPublishedNode('oe_news', 'Revision history article');
    $this->createRecord($node->id(), (int) $node->getRevisionId(), $owner->id(), $session);
    $node->setNewRevision();
    $node->setTitle('Revision history article, edited');
    $node->save();
    $this->createRecord($node->id(), (int) $node->getRevisionId(), $owner->id(), $session);
    $history_url = $node->toUrl('version-history')->toString();
    $session_url = $session->toUrl('canonical')->toString();

    $this->drupalLogin($owner);
    $this->drupalGet($history_url);
    $this->assertSession()->elementsCount('css', '.ai-content-provenance-badge', 2);
    $this->assertSession()->linkByHrefExists($session_url);

    $this->drupalLogin($this->drupalCreateUser(['access content', 'view all revisions']));
    $this->drupalGet($history_url);
    $this->assertSession()->elementsCount('css', '.ai-content-provenance-badge', 2);
    $this->assertSession()->linkByHrefNotExists($session_url);
  }

  /**
   * Creates a provenance record for a node revision.
   */
  private function createRecord(int|string $nid, int $revision_id, int|string $uid, AiEditorialSessionInterface $session): void {
    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $nid,
      'revision_id' => $revision_id,
      'uid' => $uid,
      'session' => $session->id(),
      'tokens_input' => 1,
      'tokens_output' => 2,
      'tokens_total' => 3,
      'provider' => 'mock',
      'model' => 'mock-model',
    ])->save();
  }

}
