<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Url;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessage;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;

/**
 * Tests the AI conversation history admin page.
 *
 * @group oe_ai_assistant
 */
class AiConversationHistoryTest extends AiEditorialSessionBrowserTestBase {

  /**
   * Tests access to the conversation history page.
   */
  public function testAccess(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $url = $session->toUrl('history');

    // Anonymous is denied.
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(403);

    // An authenticated user without a relevant permission is denied.
    $this->drupalLogin($this->createUser());
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(403);

    // The overview permission grants access, even though this reviewer
    // does not own the session. This page is a technical/debug overview
    // of the raw conversation for developers, not end-user content, so
    // cross-session visibility is intentional.
    $reviewer = $this->createUser(['access ai conversation message overview']);
    $this->drupalLogin($reviewer);
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(200);

    // The admin permission also grants access, again across sessions.
    $admin = $this->createUser(['administer ai conversation messages']);
    $this->drupalLogin($admin);
    $this->drupalGet($url);
    $assert_session->statusCodeEquals(200);
  }

  /**
   * Tests that message body fields and telemetry render, and NULLs do not.
   */
  public function testMessageDetailsAndTelemetry(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    // The arguments and result are deliberately JSON-encoded strings, the
    // way AI providers store them, so this pins that the display decodes
    // nested JSON instead of printing one escaped line, and that an empty
    // object stays an object instead of degrading to an empty list.
    $toolCalls = [
      ['name' => 'lookup_content', 'arguments' => '{"id":42}', 'result' => '{}'],
    ];
    $assistantMessage = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Here is the draft.',
      NULL,
      [
        'agent_id' => 'orchestrator',
        'tool_calls' => json_encode($toolCalls, JSON_THROW_ON_ERROR),
        'tokens_input' => 120,
        'tokens_output' => 80,
        'tokens_total' => 200,
        // Deliberately not a prefix of the model string below, so the
        // Provider assertion cannot be satisfied by the Model value.
        'provider' => 'openai_compatible',
        'model' => 'mistral-large-latest',
        'latency_ms' => 842,
        'finish_reason' => 'stop',
      ],
    );

    $author = $this->createUser([], 'quinn-author');
    $userMessage = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_USER,
      'Please add a summary.',
      NULL,
      ['uid' => $author->id()],
    );

    $this->visitHistoryPage($session);

    [$assistant, $user] = $this->messageRows();

    // Column-by-column assertions on the assistant row. The author cell is
    // empty because the assistant turn has no owner.
    $this->assertSame('assistant', $this->cellText($assistant, 0));
    $this->assertSame('orchestrator', $this->cellText($assistant, 1));
    $this->assertSame('', $this->cellText($assistant, 2));
    $this->assertSame($this->formattedCreated($assistantMessage), $this->cellText($assistant, 3));
    $this->assertSame('200', $this->cellText($assistant, 4));
    $this->assertSame('Here is the draft.', $this->cellText($assistant, 5));

    // The detail row has no Author item either.
    $this->assertMessageItems($this->detailRowFor($assistant), [
      'Content' => 'Here is the draft.',
      // getText() collapses the pretty-printed JSON onto one line.
      'Tool calls' => '[ { "name": "lookup_content", "arguments": { "id": 42 }, "result": {} } ]',
      'Token usage' => 'input: 120, output: 80, total: 200',
      'Provider' => 'openai_compatible',
      'Model' => 'mistral-large-latest',
      'Latency (ms)' => '842',
      'Finish reason' => 'stop',
    ]);

    // The user row carries the author in its own column, "name (uid N)".
    $this->assertSame('user', $this->cellText($user, 0));
    $this->assertSame('', $this->cellText($user, 1));
    $this->assertSame(
      sprintf('%s (uid %d)', $author->getDisplayName(), $author->id()),
      $this->cellText($user, 2),
    );
    $this->assertSame($this->formattedCreated($userMessage), $this->cellText($user, 3));
    // No token counters were recorded, so the cell is empty.
    $this->assertSame('', $this->cellText($user, 4));

    $this->assertMessageItems($this->detailRowFor($user), [
      'Author' => sprintf('%s (uid %d)', $author->getDisplayName(), $author->id()),
      'Content' => 'Please add a summary.',
    ]);
  }

  /**
   * Tests that corrupted tool_calls JSON is shown raw, not hidden.
   *
   * The buildToolCalls() method catches InvalidJsonFieldException thrown by
   * AiConversationMessage::getToolCalls() and falls back to a
   * "Tool calls (invalid JSON)" item containing the raw stored value. The
   * entity's preSave() rejects invalid JSON through the entity API, so the
   * corrupt value is written directly to the base table and the storage's
   * static cache is reset to force a fresh load on the next request.
   */
  public function testInvalidToolCallsJsonIsShownRaw(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $message = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Broken tool call.',
      NULL,
      ['agent_id' => 'orchestrator'],
    );

    \Drupal::database()->update('ai_conversation_message')
      ->fields(['tool_calls' => 'not valid json'])
      ->condition('id', $message->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('ai_conversation_message')->resetCache();

    $this->visitHistoryPage($session);

    // The fallback item replaces the usual "Tool calls" one.
    [$assistant] = $this->messageRows();
    $this->assertMessageItems($this->detailRowFor($assistant), [
      'Content' => 'Broken tool call.',
      'Tool calls (invalid JSON)' => 'not valid json',
    ]);
  }

  /**
   * Tests that a malformed stored created value renders raw, not a 500.
   *
   * Nothing in the module writes malformed values, but nothing enforces the
   * storage format on programmatic writes either. The debug page must stay
   * usable to inspect exactly this kind of corruption, so the formatter
   * falls back to the raw stored string, like the invalid tool calls JSON.
   * The corrupt value is written directly to the base table because the
   * entity API would stamp a valid value.
   */
  public function testMalformedCreatedValueIsShownRaw(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $message = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Broken timestamp.',
    );

    \Drupal::database()->update('ai_conversation_message')
      ->fields(['created' => 'not-a-datetime'])
      ->condition('id', $message->id())
      ->execute();
    \Drupal::entityTypeManager()->getStorage('ai_conversation_message')->resetCache();

    $this->visitHistoryPage($session);

    [$row] = $this->messageRows();
    $this->assertSame('not-a-datetime', $this->cellText($row, 3));
  }

  /**
   * Tests that depth classes cap at 3 while the tree glyphs keep growing.
   */
  public function testDepthClassCap(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $parent = NULL;
    $messages = [];
    foreach (range(0, 4) as $depth) {
      $parent = $this->createConversationMessage(
        $session,
        AiConversationMessageInterface::ROLE_ASSISTANT,
        sprintf('Depth %d turn.', $depth),
        $parent,
      );
      $messages[$depth] = $parent;
    }

    $this->visitHistoryPage($session);

    // Levels 0 to 3 map one to one; deeper levels reuse the cap class so
    // they keep the darkest background tint.
    foreach ([0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 3] as $depth => $expected) {
      $row = $assert_session->elementExists(
        'css',
        sprintf('tr.ai-history-message[data-message-id="%d"]', $messages[$depth]->id()),
      );
      $this->assertTrue($row->hasClass('ai-history-depth-' . $expected));
    }

    // The glyphs are not capped: a chain of only children renders three
    // blank continuation segments before the corner at depth 4.
    $deepest = $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-message[data-message-id="%d"]', $messages[4]->id()),
    );
    $this->assertSame('            └── ', $deepest->find('css', '.ai-history-tree')->getHtml());
  }

  /**
   * Tests that content which is a JSON structure is pretty-printed inline.
   *
   * Sub-agents with structured output store raw single-line JSON strings as
   * message content. The snippet cell keeps the raw text, while the detail
   * row reformats it for readability.
   */
  public function testJsonContentIsPrettyPrinted(): void {
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      '{"title":[{"value":"New Policy"}],"tags":["ai","policy"]}',
      NULL,
      ['agent_id' => 'main_fields'],
    );
    // Content that is only a JSON scalar stays untouched.
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      '"just a quoted string"',
    );

    $this->visitHistoryPage($session);

    [$structured, $scalar] = $this->messageRows();

    // The snippet cell keeps the stored single-line value.
    $this->assertSame('{"title":[{"value":"New Policy"}],"tags":["ai","policy"]}', $this->cellText($structured, 5));

    // getText() collapses the pretty-printed JSON onto one line.
    $this->assertMessageItems($this->detailRowFor($structured), [
      'Content' => '{ "title": [ { "value": "New Policy" } ], "tags": [ "ai", "policy" ] }',
    ]);
    $this->assertMessageItems($this->detailRowFor($scalar), [
      'Content' => '"just a quoted string"',
    ]);
  }

  /**
   * Tests that error messages get error styling and stay nested in place.
   */
  public function testErrorMessagesAreDistinctAndShownInPlace(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $assistantTurn = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Calling a tool.',
      NULL,
      ['agent_id' => 'orchestrator'],
    );
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ERROR,
      'Nested tool failure.',
      $assistantTurn,
    );
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ERROR,
      'Standalone failure.',
    );

    $this->visitHistoryPage($session);

    // Only the 2 error messages get the error row class.
    $assert_session->elementsCount('css', 'tr.ai-history-message.color-error', 2);
    // And no message of any other role gets it.
    $assert_session->elementNotExists('css', 'tr.color-error:not(.ai-history-role-error)');

    // The nested error points at its parent assistant turn.
    $assistantRow = $assert_session->elementExists('css', 'tr.ai-history-role-assistant');
    $nestedError = $assert_session->elementExists('css', sprintf(
      'tr.ai-history-role-error[data-parent-id="%s"]',
      $assistantRow->getAttribute('data-message-id'),
    ));
    $this->assertMessageItems($this->detailRowFor($nestedError), ['Content' => 'Nested tool failure.']);

    // The standalone error is a top-level turn.
    $topLevelErrors = $this->getSession()->getPage()->findAll('css', 'tr.ai-history-role-error[data-parent-id=""]');
    $this->assertCount(1, $topLevelErrors);
    $this->assertMessageItems($this->detailRowFor($topLevelErrors[0]), ['Content' => 'Standalone failure.']);
  }

  /**
   * Tests that per-message and session-wide token totals are correct.
   */
  public function testTokenTotals(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $parent = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Root turn.',
      NULL,
      // All three counters set at once: the total (200) must be used as-is,
      // not summed with input + output on top (which would double-count and
      // yield 400).
      ['agent_id' => 'orchestrator', 'tokens_input' => 120, 'tokens_output' => 80, 'tokens_total' => 200],
    );
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_TOOL,
      'Tool child.',
      $parent,
      ['tokens_total' => 50],
    );
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_SYSTEM,
      'Split-counter child.',
      $parent,
      ['tokens_input' => 15, 'tokens_output' => 10],
    );
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_USER,
      'Second top-level turn.',
      NULL,
      ['tokens_total' => 100],
    );

    $this->visitHistoryPage($session);

    // A parent shows its whole subtree (200 + 50 + 25), a leaf only its own.
    $parentRow = $assert_session->elementExists('css', 'tr.ai-history-role-assistant');
    $this->assertSame('subtree: 275', $this->cellText($parentRow, 4));
    $this->assertSame('50', $this->cellText(
      $assert_session->elementExists('css', 'tr.ai-history-role-tool'), 4,
    ));

    // The system leaf falls back to input + output when total is NULL.
    $this->assertSame('25', $this->cellText(
      $assert_session->elementExists('css', 'tr.ai-history-role-system'), 4,
    ));
    $this->assertSame('100', $this->cellText(
      $assert_session->elementExists('css', 'tr.ai-history-role-user'), 4,
    ));

    // Session totals: 4 messages, grand total 200 + 50 + 25 + 100 = 375.
    $assert_session->pageTextContains('4 messages, 375 tokens');
  }

  /**
   * Tests that an empty session shows the empty-state message only.
   */
  public function testEmptySession(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $this->visitHistoryPage($session);

    $assert_session->pageTextContains('This session has no conversation messages yet.');
    $assert_session->pageTextNotContains('Session totals');
    $assert_session->elementNotExists('css', 'table.ai-history-table');
    $assert_session->elementNotExists('css', 'tr.ai-history-message');
  }

  /**
   * Tests that stored message text is escaped, not filtered, in the page.
   *
   * Every table cell value is passed as a '#plain_text' render array, which
   * escapes stored markup instead of running it through the more permissive
   * admin XSS filter that a plain string would get.
   */
  public function testStoredMarkupIsEscaped(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $content = '<b>BOLDINJECTION</b> <script>alert(1)</script> <img src="https://evil.example/pixel.png" onerror="alert(2)">';
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      $content,
      NULL,
      [
        'agent_id' => '<em>EMINJECTION</em>',
        'model' => '<script>alert(3)</script>',
        'finish_reason' => '<img src="https://evil.example/finish.png" onerror="alert(4)">',
      ],
    );

    // Short enough that a complete <img> tag reaches the snippet cell. The
    // first message's tags are cut mid-attribute by truncation, which Xss
    // would drop anyway, so on their own they could not catch a reverted fix.
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_SYSTEM,
      '<img src="https://evil.example/pixel.png">',
      NULL,
      ['agent_id' => '<b>AGENTBOLD</b>'],
    );

    $this->visitHistoryPage($session);

    // Load-bearing: no real element was ever produced inside the table.
    // These fail on a reverted '#plain_text' regardless of truncation or of
    // changes to the XSS admin tag list.
    $assert_session->elementNotExists('css', 'table.ai-history-table img');
    $assert_session->elementNotExists('css', 'table.ai-history-table b');
    $assert_session->elementNotExists('css', 'table.ai-history-table em');
    $assert_session->elementNotExists('css', 'table.ai-history-table script');

    // Escaped versions are present, and the raw tags are not, anywhere on
    // the page.
    $assert_session->assertEscaped('<b>BOLDINJECTION</b>');
    $assert_session->assertEscaped('<em>EMINJECTION</em>');
    $assert_session->responseNotContains('<b>BOLDINJECTION</b>');
    $assert_session->responseNotContains('<em>EMINJECTION</em>');
    $assert_session->responseNotContains('<img src="https://evil.example/pixel.png"');

    [$longRow, $shortRow] = $this->messageRows();

    // The agent cell escapes the stored markup as literal text.
    $this->assertSame('<em>EMINJECTION</em>', $this->cellText($longRow, 1));
    $this->assertSame('<b>AGENTBOLD</b>', $this->cellText($shortRow, 1));

    // The 108-char content is cut at 80, mid-way through the image URL. The
    // truncation point is part of the contract, so it is pinned here.
    $this->assertSame(
      '<b>BOLDINJECTION</b> <script>alert(1)</script> <img src="https://evil.example/pi...',
      $this->cellText($longRow, 5),
    );

    // The second message is short enough to escape truncation, so its whole
    // snippet can be pinned: every tag survives as literal text.
    $this->assertSame('<img src="https://evil.example/pixel.png">', $this->cellText($shortRow, 5));

    // The detail row escapes every value, untruncated, and adds nothing else.
    $this->assertMessageItems($this->detailRowFor($longRow), [
      'Content' => $content,
      'Model' => '<script>alert(3)</script>',
      'Finish reason' => '<img src="https://evil.example/finish.png" onerror="alert(4)">',
    ]);
  }

  /**
   * Tests a whole conversation: order, depth and siblings in one assertion.
   */
  public function testConversationTreeRendering(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);
    $session->set('label', 'Nesting session')->save();

    $this->createConversationMessage($session, AiConversationMessageInterface::ROLE_USER, 'Draft a news article.', NULL, ['uid' => $owner->id()]);
    $orchestrator = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Delegating to sub-agents.',
      NULL,
      ['agent_id' => 'orchestrator', 'tokens_total' => 300],
    );
    $titleAgent = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_SYSTEM,
      'You are the title agent.',
      $orchestrator,
      ['agent_id' => 'title_agent'],
    );
    $titleDraft = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'New Policy Announced',
      $titleAgent,
      ['agent_id' => 'title_agent', 'tokens_total' => 50],
    );
    $bodyError = $this->createConversationMessage($session, AiConversationMessageInterface::ROLE_ERROR, 'Body agent timed out.', $orchestrator);
    $this->createConversationMessage($session, AiConversationMessageInterface::ROLE_ASSISTANT, 'Draft generated.', NULL, ['agent_id' => 'orchestrator']);

    $this->visitHistoryPage($session);

    // Scoped to the <h1> so this proves the _title_callback actually fired.
    $this->assertSame(
      'Conversation history: Nesting session',
      $assert_session->elementExists('css', 'h1')->getText(),
    );

    $this->assertCount(6, $this->messageRows());
    $this->assertSame([
      '0:user:Draft a news article.',
      '0:assistant:Delegating to sub-agents.',
      '1:system:You are the title agent.',
      '2:assistant:New Policy Announced',
      '1:error:Body agent timed out.',
      '0:assistant:Draft generated.',
    ], $this->conversationShape());

    // Box-drawing tree glyphs draw the hierarchy: the depth-2 draft is the
    // last child of a sub-agent that has a following sibling, the sub-agent
    // itself is a tee, and the trailing error is a corner. Top-level turns
    // carry no glyphs. The depth class still drives the tinted background
    // of each nesting level, detail row included.
    $deepRow = $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-message.ai-history-depth-2[data-message-id="%d"]', $titleDraft->id()),
    );
    $this->assertSame('│   └── ', $deepRow->find('css', '.ai-history-tree')->getHtml());
    $this->assertSame(
      '├── ',
      $assert_session->elementExists('css', 'tr.ai-history-role-system .ai-history-tree')->getHtml(),
    );
    $this->assertSame(
      '└── ',
      $assert_session->elementExists('css', 'tr.ai-history-role-error .ai-history-tree')->getHtml(),
    );
    $this->assertNull($this->messageRows()[0]->find('css', '.ai-history-tree'));
    $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-detail.ai-history-depth-2[data-detail-for="%d"]', $titleDraft->id()),
    );

    // The orchestrator caret announces both direct children, space
    // separated per the aria-controls id list format.
    $orchestratorRow = $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-message[data-message-id="%d"]', $orchestrator->id()),
    );
    $this->assertSame(
      sprintf('ai-history-message-%d ai-history-message-%d', $titleAgent->id(), $bodyError->id()),
      $assert_session->elementExists('css', 'button.ai-history-toggle-children', $orchestratorRow)->getAttribute('aria-controls'),
    );

    $assert_session->pageTextContains('6 messages, 350 tokens');
  }

  /**
   * Tests table skeleton, toggle wiring, aria attributes and asset library.
   */
  public function testTableStructureAndAssets(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $parent = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'Parent turn.',
      NULL,
      ['agent_id' => 'orchestrator'],
    );
    $child = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_TOOL,
      'Child call.',
      $parent,
    );

    $this->visitHistoryPage($session);

    // The history library's assets are attached to the page.
    $assert_session->responseContains('oe_ai_assistant/js/session-history.js');
    $assert_session->responseContains('oe_ai_assistant/css/session-history.css');

    // The page carries the session's own cache tag and the message list
    // tag, so edits and new messages invalidate cached copies.
    $assert_session->responseHeaderContains('X-Drupal-Cache-Tags', 'ai_conversation_message_list');
    $assert_session->responseHeaderContains('X-Drupal-Cache-Tags', 'ai_editorial_session:' . $session->id());

    // The bulk toolbar is server-rendered hidden: only the JS behavior
    // reveals it, since without JS the page is already fully expanded. Both
    // buttons carry their flip label in a data attribute for the behavior.
    $toolbar = $assert_session->elementExists('css', '.ai-history .ai-history-toolbar[hidden]');
    $expandAll = $assert_session->elementExists('css', 'button.ai-history-expand-all', $toolbar);
    $this->assertSame('false', $expandAll->getAttribute('aria-expanded'));
    $this->assertSame('Expand all', $expandAll->getText());
    $this->assertSame('Expand all', $expandAll->getAttribute('data-label-show'));
    $this->assertSame('Collapse all', $expandAll->getAttribute('data-label-hide'));
    $detailsAll = $assert_session->elementExists('css', 'button.ai-history-details-all', $toolbar);
    $this->assertSame('false', $detailsAll->getAttribute('aria-expanded'));
    $this->assertSame('Show all details', $detailsAll->getText());
    $this->assertSame('Show all details', $detailsAll->getAttribute('data-label-show'));
    $this->assertSame('Hide all details', $detailsAll->getAttribute('data-label-hide'));

    // The table renders with a sticky header and no icon columns: the
    // caret nests inside the role cell and the row itself is the detail
    // toggle.
    $assert_session->elementExists('css', '.ai-history table.ai-history-table.sticky-header');
    $headers = array_map(
      static fn (NodeElement $cell): string => $cell->getText(),
      $this->getSession()->getPage()->findAll('css', 'table.ai-history-table thead th'),
    );
    $this->assertSame(['Role', 'Agent', 'Author', 'Created', 'Tokens', 'Content'], $headers);
    $assert_session->elementNotExists('css', 'button.ai-history-toggle-detail');

    // Every message row is paired with exactly one detail row.
    $assert_session->elementsCount('css', 'tr.ai-history-message', 2);
    $assert_session->elementsCount('css', 'tr.ai-history-detail', 2);

    // The parent's caret sits inside the role cell, server-rendered
    // expanded, and points at the direct child row; the childless row gets
    // an alignment spacer instead of a button.
    $parentRow = $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-message[data-message-id="%d"]', $parent->id()),
    );
    $caret = $parentRow->findAll('css', 'td')[0]->find('css', 'button.ai-history-toggle-children');
    $this->assertNotNull($caret);
    $this->assertSame('true', $caret->getAttribute('aria-expanded'));
    $this->assertSame('ai-history-message-' . $child->id(), $caret->getAttribute('aria-controls'));
    $childRow = $assert_session->elementExists(
      'css',
      sprintf('tr.ai-history-message[data-message-id="%d"]', $child->id()),
    );
    $assert_session->elementNotExists('css', 'button.ai-history-toggle-children', $childRow);
    $this->assertNotNull($childRow->findAll('css', 'td')[0]->find('css', '.ai-history-caret-spacer'));

    // Each message row is itself the detail toggle: focusable, announcing
    // its expanded state, and pointing at its detail row, which carries a
    // matching id and back-reference.
    foreach ([$parentRow, $childRow] as $row) {
      $id = $row->getAttribute('data-message-id');
      $this->assertSame('0', $row->getAttribute('tabindex'));
      $this->assertSame('true', $row->getAttribute('aria-expanded'));
      $this->assertSame('ai-history-detail-' . $id, $row->getAttribute('aria-controls'));
      $assert_session->elementExists(
        'css',
        sprintf('tr#ai-history-detail-%s.ai-history-detail[data-detail-for="%s"]', $id, $id),
      );
      $assert_session->elementExists('css', sprintf('tr#ai-history-message-%s', $id));
    }

    // Tree wiring: the child references its parent, top-level rows carry an
    // empty parent pointer, and the child gets a corner glyph as the only
    // (hence last) child. Depth classes drive the background hierarchy on
    // message and detail rows alike, and detail rows expose their depth as
    // a custom property for the indentation.
    $this->assertSame((string) $parent->id(), $childRow->getAttribute('data-parent-id'));
    $this->assertSame('', $parentRow->getAttribute('data-parent-id'));
    $this->assertSame('└── ', $childRow->find('css', '.ai-history-tree')->getHtml());
    $this->assertNull($parentRow->find('css', '.ai-history-tree'));
    $this->assertTrue($parentRow->hasClass('ai-history-depth-0'));
    $this->assertTrue($childRow->hasClass('ai-history-depth-1'));
    $this->assertTrue($this->detailRowFor($childRow)->hasClass('ai-history-depth-1'));
    $this->assertSame('--ai-history-depth: 1', $this->detailRowFor($childRow)->getAttribute('style'));
    $this->assertSame('--ai-history-depth: 0', $this->detailRowFor($parentRow)->getAttribute('style'));
  }

  /**
   * Tests that the collection page exposes a History operation link.
   */
  public function testHistoryOperationLinksFromCollection(): void {
    $assert_session = $this->assertSession();
    $user = $this->createUser([
      'view_update own sessions',
      'access ai conversation message overview',
    ]);
    $session = $this->createSession($user);
    $session->set('label', 'Session with history link')->save();

    $this->drupalLogin($user);
    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));

    $assert_session->statusCodeEquals(200);
    $assert_session->linkByHrefExists($session->toUrl('history')->toString());

    // A user who can view their own session but lacks the message overview
    // (or admin) permission does not get a History operation link for it:
    // the access guard in AiEditorialSessionListBuilder::getDefaultOperations()
    // is respected, not just skipped for everyone.
    $userWithoutMessageAccess = $this->createUser(['view_update own sessions']);
    $theirSession = $this->createSession($userWithoutMessageAccess);
    $this->drupalLogin($userWithoutMessageAccess);
    $this->drupalGet(Url::fromRoute('entity.ai_editorial_session.collection'));

    $assert_session->statusCodeEquals(200);
    $assert_session->linkByHrefNotExists($theirSession->toUrl('history')->toString());
  }

  /**
   * Asserts the complete set of detail items rendered for one message.
   *
   * @param \Behat\Mink\Element\NodeElement $detailRow
   *   The message's detail row.
   * @param array $expected
   *   The expected item labels mapped to their values, in render order.
   */
  private function assertMessageItems(NodeElement $detailRow, array $expected): void {
    $actual = [];
    $items = './/div[contains(concat(" ", @class, " "), " form-item ")]';
    foreach ($detailRow->findAll('xpath', $items) as $item) {
      $label = $item->find('css', 'label');
      $text = $item->getText();
      $label_text = $label ? $label->getText() : '';
      $actual[$label_text] = trim(substr($text, strlen($label_text)));
    }
    $this->assertSame($expected, $actual);
  }

  /**
   * Returns every message row, in document order.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   The tr element of each message, nested ones included.
   */
  private function messageRows(): array {
    return $this->getSession()->getPage()->findAll('css', 'tr.ai-history-message');
  }

  /**
   * Returns the detail row paired with a message row.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The message row.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The detail row.
   */
  private function detailRowFor(NodeElement $row): NodeElement {
    return $this->assertSession()->elementExists(
      'css',
      sprintf('tr.ai-history-detail[data-detail-for="%s"]', $row->getAttribute('data-message-id')),
    );
  }

  /**
   * Returns the text of one cell of a message row.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The message row.
   * @param int $index
   *   The zero-based cell index: 0 role (with tree glyphs and caret),
   *   1 agent, 2 author, 3 created, 4 tokens, 5 content snippet.
   *
   * @return string
   *   The cell text.
   */
  private function cellText(NodeElement $row, int $index): string {
    return $row->findAll('css', 'td')[$index]->getText();
  }

  /**
   * Returns a message's created value as the page formats it.
   *
   * The datetime field stores an UTC string; the page renders it through
   * the date formatter's short format in the site timezone.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message
   *   The message.
   *
   * @return string
   *   The formatted date.
   */
  private function formattedCreated(AiConversationMessageInterface $message): string {
    $date = DrupalDateTime::createFromFormat(
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      (string) $message->get('created')->value,
      DateTimeItemInterface::STORAGE_TIMEZONE,
    );
    return \Drupal::service('date.formatter')->format($date->getTimestamp(), 'short');
  }

  /**
   * Returns the rendered conversation as one "depth:role:content" line each.
   *
   * Depth is computed by following data-parent-id pointers, so this asserts
   * the actual tree wiring the client-side toggles rely on.
   *
   * @return string[]
   *   One entry per message row, in document order.
   */
  private function conversationShape(): array {
    $shape = [];
    foreach ($this->messageRows() as $row) {
      preg_match('/ai-history-role-(\w+)/', (string) $row->getAttribute('class'), $matches);
      $shape[] = sprintf('%d:%s:%s', $this->rowDepth($row), $matches[1], $this->cellText($row, 5));
    }
    return $shape;
  }

  /**
   * Counts how many parent pointers lead from a row to a top-level turn.
   *
   * @param \Behat\Mink\Element\NodeElement $row
   *   The message row to measure.
   *
   * @return int
   *   Zero for a top-level turn, one for its child, and so on.
   */
  private function rowDepth(NodeElement $row): int {
    $depth = 0;
    $parent_id = (string) $row->getAttribute('data-parent-id');
    while ($parent_id !== '') {
      $depth++;
      $parent = $this->assertSession()->elementExists(
        'css',
        sprintf('tr.ai-history-message[data-message-id="%s"]', $parent_id),
      );
      $parent_id = (string) $parent->getAttribute('data-parent-id');
    }
    return $depth;
  }

  /**
   * Creates and saves a conversation message hosted by a session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the message.
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message content.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The parent message, or NULL for a top-level turn.
   * @param array $values
   *   Additional base field values to set on the message (e.g. agent_id,
   *   uid, tokens_input, tool_calls as a JSON string, provider).
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface
   *   The saved message.
   */
  private function createConversationMessage(
    AiEditorialSessionInterface $session,
    string $role,
    string $content = '',
    ?AiConversationMessageInterface $parent = NULL,
    array $values = [],
  ): AiConversationMessageInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = AiConversationMessage::create($values + [
      'host_entity_type' => 'ai_editorial_session',
      'host_entity_id' => (int) $session->id(),
      'role' => $role,
      'content' => $content,
      'parent' => $parent?->id(),
    ]);
    $message->save();
    return $message;
  }

  /**
   * Visits a session's history page as a user with overview access.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose history page to visit.
   */
  private function visitHistoryPage(AiEditorialSessionInterface $session): void {
    $reviewer = $this->createUser(['access ai conversation message overview']);
    $this->drupalLogin($reviewer);
    $this->drupalGet($session->toUrl('history'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
