<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\oe_ai_assistant\Entity\AiContentProvenance;
use Drupal\user\UserInterface;

/**
 * Functional tests for the AI usage dashboard drill-down views and export.
 */
class AiUsageDashboardViewsTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests the four drill-down views render their tables and tabs.
   */
  public function testDrilldownViews(): void {
    $user = $this->drupalCreateUser([
      'access administration pages',
      'access content overview',
      'access ai usage dashboard',
    ]);
    $this->drupalLogin($user);

    $node = $this->createPublishedNode('oe_news', 'EU climate funding 2026');
    $session = $this->createSession($user, $node);
    // A single record within the default "last 30 days" preset window.
    $this->createRecord($node, $user, (int) $session->id(), 1234);

    // The default route renders the overview body.
    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tokens consumed');
    // The overview offers a CSV export of its metrics and breakdowns.
    $this->assertSession()->linkExists('Export CSV');

    // Each drill-down view renders its banner, table headers, and the node.
    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.view', ['view' => 'nodes']));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('List of nodes supported with AI');
    $this->assertSession()->pageTextContains('Majors');
    $this->assertSession()->pageTextContains('EU climate funding 2026');
    $this->assertSession()->pageTextContains('1,234');
    // The export link appears on the table views.
    $this->assertSession()->linkExists('Export CSV');

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.view', ['view' => 'major-versions']));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Major versions per node');
    $this->assertSession()->pageTextContains('Version');

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.view', ['view' => 'sessions']));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Sessions per major version');
    $this->assertSession()->pageTextContains('Session ID');

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.view', ['view' => 'users']));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tokens per user');
    $this->assertSession()->pageTextContains('Nodes edited with AI');
    // The author filter is relabelled on the users view.
    $this->assertSession()->pageTextContains('Participants');
  }

  /**
   * Tests the CSV export streams the current view's data.
   */
  public function testCsvExport(): void {
    $user = $this->drupalCreateUser([
      'access administration pages',
      'access content overview',
      'access ai usage dashboard',
    ]);
    $this->drupalLogin($user);

    $node = $this->createPublishedNode('oe_news', 'EU climate funding 2026');
    $session = $this->createSession($user, $node);
    $this->createRecord($node, $user, (int) $session->id(), 1234);

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.export', [], [
      'query' => ['view' => 'nodes', 'preset' => '30-days'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'ai-usage-nodes.csv');

    $csv = $this->getSession()->getPage()->getContent();
    // The header row and the node data row are both present.
    $this->assertStringContainsString('Node,ID,Type,Participant,Template,Majors,Sessions', $csv);
    $this->assertStringContainsString('EU climate funding 2026', $csv);
    $this->assertStringContainsString('"1,234"', $csv);

    // The overview exports a section/label/value summary of its metrics and
    // breakdowns.
    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.export', [], [
      'query' => ['view' => 'overview', 'preset' => '30-days'],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'ai-usage-overview.csv');

    $csv = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('Section,Label,Value', $csv);
    $this->assertStringContainsString('Metric,"Tokens consumed","1,234"', $csv);
    $this->assertStringContainsString('"Tokens by content type",News,"1,234"', $csv);
  }

  /**
   * Tests the dashboard denies users without the permission.
   */
  public function testAccessDenied(): void {
    $user = $this->drupalCreateUser(['access content overview']);
    $this->drupalLogin($user);

    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.view', ['view' => 'nodes']));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet(Url::fromRoute('oe_ai_assistant.usage_dashboard.export', [], [
      'query' => ['view' => 'nodes'],
    ]));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Creates a provenance record within the last-30-days preset window.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The tracked node.
   * @param \Drupal\user\UserInterface $user
   *   The author.
   * @param int $sessionId
   *   The editorial session ID.
   * @param int $tokens
   *   The total tokens.
   */
  private function createRecord(NodeInterface $node, UserInterface $user, int $sessionId, int $tokens): void {
    AiContentProvenance::create([
      'entity_type' => 'node',
      'entity_id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'uid' => $user->id(),
      'session' => $sessionId,
      'template' => 'news_default',
      'version_major' => 1,
      'tokens_input' => 0,
      'tokens_output' => 0,
      'tokens_total' => $tokens,
      'provider' => 'mock',
      'model' => 'mock-model',
      'created' => \Drupal::time()->getRequestTime(),
    ])->save();
  }

}
