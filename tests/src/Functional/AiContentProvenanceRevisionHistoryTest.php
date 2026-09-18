<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\oe_ai_assistant\Entity\AiContentProvenance;

/**
 * Tests the node revision history page provenance badge.
 *
 * @group oe_ai_assistant
 */
final class AiContentProvenanceRevisionHistoryTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests that the revision history page shows a provenance badge.
   */
  public function testRevisionHistoryShowsProvenanceBadge(): void {
    $user = $this->drupalCreateUser([
      'access content',
      'view all revisions',
      'view_update own sessions',
    ]);
    $this->drupalLogin($user);

    $session = $this->createSession($user);
    $node = $this->createPublishedNode('oe_news', 'Revision history article');
    $revision_id = (int) $node->getRevisionId();

    $this->drupalGet($node->toUrl('version-history')->toString());
    $initial_html = $this->getSession()->getPage()->getContent();
    $this->assertStringNotContainsString('ai-content-provenance-badge', $initial_html);
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'ai_content_provenance_list');

    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => $revision_id,
      'uid' => $user->id(),
      'session' => $session->id(),
      'tokens_input' => 1,
      'tokens_output' => 2,
      'tokens_total' => 3,
      'provider' => 'mock',
      'model' => 'mock-model',
    ])->save();

    $this->drupalGet($node->toUrl('version-history')->toString(), [
      'query' => [
        'cache-bust' => '1',
      ],
    ]);
    $html = $this->getSession()->getPage()->getContent();

    $this->assertStringContainsString('ai-content-provenance-badge', $html);
    $this->assertStringContainsString('AI-assisted', $html);
    $this->assertStringContainsString($session->toUrl('canonical')->toString(), $html);
  }

}
