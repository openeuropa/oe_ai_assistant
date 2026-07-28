<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;
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

    $toolCalls = [
      ['name' => 'lookup_content', 'arguments' => ['id' => 42]],
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

    [$assistant, $user] = $this->messageElements();

    $this->assertSame(
      sprintf(
        'assistant | orchestrator | %s | 200 tokens | Here is the draft.',
        $assistantMessage->get('created')->value,
      ),
      $assistant->find('css', 'summary')->getText(),
    );

    // The assistant turn has no owner, hence no Author item.
    $this->assertMessageItems($assistant, [
      'Content' => 'Here is the draft.',
      // getText() collapses the pretty-printed JSON onto one line.
      'Tool calls' => '[ { "name": "lookup_content", "arguments": { "id": 42 } } ]',
      'Token usage' => 'input: 120, output: 80, total: 200',
      'Provider' => 'openai_compatible',
      'Model' => 'mistral-large-latest',
      'Latency (ms)' => '842',
      'Finish reason' => 'stop',
    ]);

    // The summary carries the bare display name, unlike the "name (uid N)"
    // body item.
    $this->assertSame(
      sprintf(
        'user | %s | %s | Please add a summary.',
        $author->getDisplayName(),
        $userMessage->get('created')->value,
      ),
      $user->find('css', 'summary')->getText(),
    );

    $this->assertMessageItems($user, [
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
    [$assistant] = $this->messageElements();
    $this->assertMessageItems($assistant, [
      'Content' => 'Broken tool call.',
      'Tool calls (invalid JSON)' => 'not valid json',
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

    // Only 2 messages should be styled as an error.
    $assert_session->elementsCount('css', 'details.ai-history-message.messages.messages--error', 2);
    // And no message of any other role should be.
    $assert_session->elementNotExists('css', 'details.ai-history-message.messages--error:not(.ai-history-role-error)');

    // The nested error is searched inside its parent assistant turn.
    $assistantElement = $assert_session->elementExists('css', 'details.ai-history-role-assistant');
    $nestedError = $assert_session->elementExists('css', 'details.ai-history-role-error', $assistantElement);
    $this->assertMessageItems($nestedError, ['Content' => 'Nested tool failure.']);

    // The standalone error is not nested inside any other details element.
    $topLevelErrors = $this->getSession()->getPage()->findAll(
      'xpath',
      '//details[contains(concat(" ", @class, " "), " ai-history-role-error ") and not(ancestor::details)]',
    );
    $this->assertCount(1, $topLevelErrors);
    $this->assertMessageItems($topLevelErrors[0], ['Content' => 'Standalone failure.']);
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
    $toolChild = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_TOOL,
      'Tool child.',
      $parent,
      ['tokens_total' => 50],
    );
    $systemChild = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_SYSTEM,
      'Split-counter child.',
      $parent,
      ['tokens_input' => 15, 'tokens_output' => 10],
    );
    $secondTurn = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_USER,
      'Second top-level turn.',
      NULL,
      ['tokens_total' => 100],
    );

    $this->visitHistoryPage($session);

    // A parent shows its whole subtree (200 + 50 + 25), a leaf only its own.
    $parentElement = $assert_session->elementExists('css', 'details.ai-history-role-assistant');
    $this->assertSame(
      sprintf('assistant | orchestrator | %s | subtree: 275 tokens | Root turn.', $parent->get('created')->value),
      $assert_session->elementExists('css', 'summary', $parentElement)->getText(),
    );
    $this->assertSame(
      sprintf('tool | %s | 50 tokens | Tool child.', $toolChild->get('created')->value),
      $assert_session->elementExists('css', 'details.ai-history-role-tool > summary', $parentElement)->getText(),
    );

    // The system leaf falls back to input + output when total is NULL.
    $this->assertSame(
      sprintf('system | %s | 25 tokens | Split-counter child.', $systemChild->get('created')->value),
      $assert_session->elementExists('css', 'details.ai-history-role-system > summary', $parentElement)->getText(),
    );
    $this->assertSame(
      sprintf('user | %s | 100 tokens | Second top-level turn.', $secondTurn->get('created')->value),
      $assert_session->elementExists('css', 'details.ai-history-role-user > summary')->getText(),
    );

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
    $assert_session->elementNotExists('css', 'details.ai-history-message');
  }

  /**
   * Tests that stored message text is escaped, not filtered, in the page.
   *
   * A plain string '#title' would be wrapped in '#markup' by
   * template_preprocess_details() and only XSS-filtered against the admin tag
   * list, leaving '<b>', '<em>' and '<img>' intact. The controller passes a
   * '#plain_text' render array instead, which escapes them.
   */
  public function testStoredMarkupIsEscaped(): void {
    $assert_session = $this->assertSession();
    $owner = $this->createUser();
    $session = $this->createSession($owner);

    $content = '<b>BOLDINJECTION</b> <script>alert(1)</script> <img src="https://evil.example/pixel.png" onerror="alert(2)">';
    $longMessage = $this->createConversationMessage(
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

    // Short enough that a complete <img> tag reaches the summary. The first
    // message's tags are cut mid-attribute by truncation, which Xss would
    // drop anyway, so on their own they could not catch a reverted fix.
    $shortMessage = $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_SYSTEM,
      '<img src="https://evil.example/pixel.png">',
      NULL,
      ['agent_id' => '<b>AGENTBOLD</b>'],
    );

    $this->visitHistoryPage($session);

    // Load-bearing: no real element was ever produced inside a summary.
    // These fail on a reverted '#plain_text' regardless of truncation or of
    // changes to the XSS admin tag list.
    $assert_session->elementNotExists('css', 'details.ai-history-message summary img');
    $assert_session->elementNotExists('css', 'details.ai-history-message summary b');
    $assert_session->elementNotExists('css', 'details.ai-history-message summary em');

    // Escaped versions are present, and the raw tags are not, anywhere on
    // the page.
    $assert_session->assertEscaped('<b>BOLDINJECTION</b>');
    $assert_session->assertEscaped('<em>EMINJECTION</em>');
    $assert_session->responseNotContains('<b>BOLDINJECTION</b>');
    $assert_session->responseNotContains('<em>EMINJECTION</em>');
    $assert_session->responseNotContains('<img src="https://evil.example/pixel.png"');

    // The 108-char content is cut at 80, mid-way through the image URL. The
    // truncation point is part of the contract, so it is pinned here.
    $this->assertSame(
      sprintf(
        'assistant | <em>EMINJECTION</em> | %s | <b>BOLDINJECTION</b> <script>alert(1)</script> <img src="https://evil.example/pi...',
        $longMessage->get('created')->value,
      ),
      $assert_session->elementExists('css', 'details.ai-history-role-assistant > summary')->getText(),
    );

    // The second message is short enough to escape truncation, so its whole
    // summary can be pinned: every tag survives as literal text.
    $this->assertSame(
      sprintf(
        'system | <b>AGENTBOLD</b> | %s | <img src="https://evil.example/pixel.png">',
        $shortMessage->get('created')->value,
      ),
      $assert_session->elementExists('css', 'details.ai-history-role-system > summary')->getText(),
    );

    // The body escapes every value, untruncated, and adds nothing else.
    [$assistantElement] = $this->messageElements();
    $this->assertMessageItems($assistantElement, [
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
    $this->createConversationMessage(
      $session,
      AiConversationMessageInterface::ROLE_ASSISTANT,
      'New Policy Announced',
      $titleAgent,
      ['agent_id' => 'title_agent', 'tokens_total' => 50],
    );
    $this->createConversationMessage($session, AiConversationMessageInterface::ROLE_ERROR, 'Body agent timed out.', $orchestrator);
    $this->createConversationMessage($session, AiConversationMessageInterface::ROLE_ASSISTANT, 'Draft generated.', NULL, ['agent_id' => 'orchestrator']);

    $this->visitHistoryPage($session);

    // Scoped to the <h1> so this proves the _title_callback actually fired.
    $this->assertSame(
      'Conversation history: Nesting session',
      $assert_session->elementExists('css', 'h1')->getText(),
    );

    $this->assertCount(6, $this->messageElements());
    $this->assertSame([
      '0:user:Draft a news article.',
      '0:assistant:Delegating to sub-agents.',
      '1:system:You are the title agent.',
      '2:assistant:New Policy Announced',
      '1:error:Body agent timed out.',
      '0:assistant:Draft generated.',
    ], $this->conversationShape());

    $assert_session->pageTextContains('6 messages, 350 tokens');
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
   * Asserts the complete set of body items rendered for one message.
   *
   * Items are direct children of the details element, so a nested
   * sub-agent message's own items are never picked up here.
   *
   * @param \Behat\Mink\Element\NodeElement $details
   *   The message's details element.
   * @param array $expected
   *   The expected item labels mapped to their values, in render order.
   */
  private function assertMessageItems(NodeElement $details, array $expected): void {
    $actual = [];
    $items = '/div[contains(concat(" ", @class, " "), " form-item ")]';
    foreach ($details->findAll('xpath', $items) as $item) {
      $label = $item->find('css', 'label');
      $text = $item->getText();
      $label_text = $label ? $label->getText() : '';
      $actual[$label_text] = trim(substr($text, strlen($label_text)));
    }
    $this->assertSame($expected, $actual);
  }

  /**
   * Returns every rendered message element, in document order.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   The details element of each message, nested ones included.
   */
  private function messageElements(): array {
    return $this->getSession()->getPage()->findAll('css', 'details.ai-history-message');
  }

  /**
   * Returns the rendered conversation as one "depth:role:content" line each.
   *
   * Timestamps are left out so the expected value stays readable; they are
   * asserted on the summary lines elsewhere.
   *
   * @return string[]
   *   One entry per message node, in document order.
   */
  private function conversationShape(): array {
    $shape = [];
    foreach ($this->messageElements() as $details) {
      $classes = (string) $details->getAttribute('class');
      preg_match('/ai-history-role-(\w+)/', $classes, $matches);
      $summary = $details->find('css', 'summary')->getText();
      $parts = explode(' | ', $summary);
      $shape[] = sprintf('%d:%s:%s', $this->detailsDepth($details), $matches[1], end($parts));
    }
    return $shape;
  }

  /**
   * Counts how many details elements enclose the given one.
   *
   * @param \Behat\Mink\Element\NodeElement $element
   *   The details element to measure.
   *
   * @return int
   *   Zero for a top-level turn, one for its child, and so on.
   */
  private function detailsDepth(NodeElement $element): int {
    return count($element->findAll('xpath', '/ancestor::details'));
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
