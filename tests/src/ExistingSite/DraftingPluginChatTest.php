<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin chat action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/chat
 * with a mock AI provider and verifies the SSE response stream. The
 * conversation is scoped by an editorial session: history and turns
 * persist as ai_conversation_message rows hosted by the session.
 *
 * Requires OE_AI_SKIP_PROVIDER_OVERRIDE=1 in the web container
 * environment so settings.ai.php does not override the mock
 * provider config set in setUp().
 *
 * @see .ddev/settings.ai.php
 * @see .ddev/docker-compose.phpunit.yaml
 */
class DraftingPluginChatTest extends ExistingSiteBase {

  use ExistingSiteConfigBackupTrait;

  /**
   * Sessions created by the test, cleared of messages on teardown.
   *
   * @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface[]
   */
  protected array $sessions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure the shared test module is enabled (provides MockAiProvider).
    \Drupal::service('module_installer')
      ->install(['oe_ai_assistant_test']);

    // Backup AI settings and set mock_ai as the default provider.
    $this->backupSimpleConfig('ai.settings');
    \Drupal::configFactory()->getEditable('ai.settings')
      ->set('default_providers', [
        'chat' => [
          'provider_id' => 'mock_ai',
          'model_id' => 'mock-model',
        ],
        'chat_with_tools' => [
          'provider_id' => 'mock_ai',
          'model_id' => 'mock-model',
        ],
      ])
      ->save();

    MockAiProvider::reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Remove any conversation messages persisted against the test sessions.
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    foreach ($this->sessions as $session) {
      $storage->deleteForHost($session);
    }

    MockAiProvider::reset();
    $this->restoreConfiguration();
    parent::tearDown();
  }

  /**
   * Tests that a text response is streamed and persisted for the session.
   */
  public function testTextResponseStreamedAsSse(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    MockAiProvider::enqueue(new MockResponse(
      text: 'Hello from the drafting assistant.',
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Hi there.',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);

    // Verify SSE lifecycle events are present.
    $types = array_column($events, 'type');
    $this->assertContains('start', $types, 'SSE must include a start event.');
    $this->assertContains('start-step', $types, 'SSE must include a start-step event.');
    $this->assertContains('finish-step', $types, 'SSE must include a finish-step event.');
    $this->assertContains('finish', $types, 'SSE must include a finish event.');

    // Verify text-delta events contain the mock response text.
    $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
    $this->assertNotEmpty($textDeltas, 'SSE must include text-delta events.');

    // Reconstruct the full streamed text from all text-delta events.
    $fullText = implode('', array_map(
      fn($e) => $e['textDelta'] ?? '',
      $textDeltas,
    ));
    $this->assertStringContainsString('Hello', $fullText,
      'Streamed text should contain the mock response.');

    // Verify [DONE] terminator is present.
    $this->assertStringContainsString('[DONE]', $result['body'],
      'SSE stream must end with [DONE].');

    // The turn is persisted: a user row and an assistant row are hosted by
    // the session, and get-messages returns them as the transcript.
    $transcript = $this->loadTranscript($session);
    $roles = array_map(fn($m) => $m->getRole(), $transcript);
    $this->assertContains('user', $roles, 'A user turn must be persisted.');
    $this->assertContains('assistant', $roles, 'An assistant turn must be persisted.');

    $messages = $this->getMessages($session);
    $this->assertSame('user', $messages[0]['role']);
    $this->assertSame('Hi there.', $messages[0]['content']);
    $this->assertSame('assistant', $messages[1]['role']);
    $this->assertStringContainsString('Hello', $messages[1]['content']);
  }

  /**
   * Tests that conversation history persists across turns via the session.
   *
   * Both requests share the same session, so history is rebuilt from the
   * persisted transcript. Turn 2's LLM call includes turn 1's user message.
   */
  public function testConversationHistoryPersists(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    // Turn 1.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Got it, you want to write about climate.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'I want to write about climate change.',
      'sessionId' => $session->id(),
    ]);

    // Turn 2 with the same session.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Sure, focusing on EU policy.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Focus on EU policy please.',
      'sessionId' => $session->id(),
    ]);

    // Check that turn 2's LLM call includes both messages.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two LLM calls should have been made.');

    $turn2Texts = array_column($log[1]['messages'], 'text');
    $this->assertContains(
      'I want to write about climate change.',
      $turn2Texts,
      'Turn 2 should include turn 1 user message from the persisted history.',
    );
    $this->assertContains(
      'Focus on EU policy please.',
      $turn2Texts,
      'Turn 2 should include the current user message.',
    );
  }

  /**
   * Tests that draft_content triggers orchestration with sub-agents.
   *
   * The target content type comes from the session, not the request body.
   */
  public function testDraftContentTriggersOrchestration(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    // Router calls draft_content (the "I'm ready" signal).
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'draft_content',
            'arguments' => '{}',
          ],
        ],
      ],
    ));
    // Sub-agent responses: one per group (main_fields,
    // field_contacts, field_content_paragraphs).
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title"}], "field_body": [{"value": "<p>Body</p>", "format": "full_html"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_contacts": []}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_content_paragraphs": []}',
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);

    // Should contain data-plan events.
    $planEvents = array_filter(
      $events,
      fn($e) => $e['type'] === 'data-plan',
    );
    $this->assertNotEmpty($planEvents,
      'Should emit data-plan events.');

    // Should contain data-drafted-fields.
    $draftedEvents = array_filter(
      $events,
      fn($e) => $e['type'] === 'data-drafted-fields',
    );
    $this->assertNotEmpty($draftedEvents,
      'Should emit data-drafted-fields event.');

    // Verify consolidated fields contain title.
    $draftedEvent = reset($draftedEvents);
    $fields = $draftedEvent['data'] ?? [];
    $this->assertArrayHasKey('title', $fields,
      'Consolidated fields should include title.');
  }

  /**
   * Tests that an empty message returns a 400 error.
   */
  public function testEmptyMessageReturns400(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => '',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(400, $result['status']);
  }

  /**
   * Tests that a missing sessionId returns a 400 error.
   */
  public function testMissingSessionReturns400(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Hello.',
    ]);

    $this->assertEquals(400, $result['status']);
  }

  /**
   * Tests that get-messages returns the session's user-visible transcript.
   *
   * Seeds rows directly so the assertion does not depend on the AI provider.
   */
  public function testGetMessagesReturnsTranscript(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedMessage($session, 'user', 'Draft a news article.');
    $this->seedMessage($session, 'assistant', 'Here is a draft.');
    // A tool row is not user-visible and must be filtered out.
    $this->seedMessage($session, 'tool', 'Tool payload.');

    $messages = $this->getMessages($session);

    $this->assertSame(
      [
        ['role' => 'user', 'content' => 'Draft a news article.'],
        ['role' => 'assistant', 'content' => 'Here is a draft.'],
      ],
      $messages,
    );
  }

  /**
   * Tests that get-messages surfaces a draft_content tool call and result.
   *
   * The drafted fields are stored as the result of the draft_content call so
   * the transcript can render a clickable trace that repopulates the artifact.
   */
  public function testGetMessagesReturnsDraftToolCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedMessage($session, 'user', 'Draft a news article.');
    // An assistant turn that triggered drafting: empty text, draft_content
    // tool call carrying the consolidated fields as its result.
    $fields = ['title' => [['value' => 'Test Title']]];
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => $fields,
      ],
    ]);

    $messages = $this->getMessages($session);

    $this->assertCount(2, $messages);
    $this->assertSame('user', $messages[0]['role']);
    $this->assertArrayNotHasKey('toolCalls', $messages[0]);

    $this->assertSame('assistant', $messages[1]['role']);
    $this->assertSame('', $messages[1]['content']);
    $this->assertArrayHasKey('toolCalls', $messages[1]);
    $this->assertSame('draft_content', $messages[1]['toolCalls'][0]['function']['name']);
    $this->assertSame($fields, $messages[1]['toolCalls'][0]['result']);
  }

  /**
   * Tests that reset clears the whole conversation for the session.
   *
   * Provider-independent: it seeds rows and asserts they are deleted.
   */
  public function testResetClearsConversation(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedMessage($session, 'user', 'Draft a news article.');
    $this->seedMessage($session, 'assistant', 'Here is a draft.');

    $result = $this->httpPost('/api/ai/plugins/drafting/reset', [
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status']);
    $this->assertSame(['status' => 'ok'], json_decode($result['body'], TRUE));

    $this->assertSame([], $this->loadTranscript($session),
      'Reset must delete every message hosted by the session.');
  }

  /**
   * Creates an editorial session owned by the given user.
   *
   * @param \Drupal\user\UserInterface $owner
   *   The session owner.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The saved session.
   */
  protected function createSession(UserInterface $owner): AiEditorialSessionInterface {
    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $owner->id(),
        'content_type' => 'oe_news',
      ]);
    $session->save();
    $this->markEntityForCleanup($session);
    $this->sessions[] = $session;
    return $session;
  }

  /**
   * Seeds a conversation message hosted by the session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message text.
   * @param array $toolCalls
   *   Optional tool calls to store on the message.
   */
  protected function seedMessage(AiEditorialSessionInterface $session, string $role, string $content, array $toolCalls = []): void {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = \Drupal::entityTypeManager()->getStorage('ai_conversation_message')
      ->create([
        'host_entity_type' => $session->getEntityTypeId(),
        'host_entity_id' => (int) $session->id(),
        'role' => $role,
        'content' => $content,
      ]);
    if ($toolCalls) {
      $message->setToolCalls($toolCalls);
    }
    $message->save();
  }

  /**
   * Loads the persisted top-level transcript for a session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface[]
   *   The transcript entities.
   */
  protected function loadTranscript(AiEditorialSessionInterface $session): array {
    \Drupal::entityTypeManager()->getStorage('ai_conversation_message')
      ->resetCache();
    return \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message')
      ->loadTranscript($session);
  }

  /**
   * Calls the get-messages action and returns its messages list.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session whose transcript to load.
   *
   * @return array
   *   The decoded messages list.
   */
  protected function getMessages(AiEditorialSessionInterface $session): array {
    $result = $this->httpPost('/api/ai/plugins/drafting/get-messages', [
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'get-messages should return 200. Body: ' . substr($result['body'], 0, 500));
    $decoded = json_decode($result['body'], TRUE);
    return $decoded['messages'] ?? [];
  }

  /**
   * Logs in a user via the login form.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account to log in.
   */
  protected function loginUser(UserInterface $account): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name' => $account->getAccountName(),
      'pass' => $account->passRaw,
    ], 'Log in');

    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Sends a POST request with JSON body using the BrowserKit client.
   *
   * @param string $url
   *   The URL to post to.
   * @param array $body
   *   The request body to encode as JSON.
   *
   * @return array
   *   An array with 'status' (int) and 'body' (raw string) keys.
   */
  protected function httpPost(string $url, array $body): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();

    $fullUrl = $this->baseUrl . $url;

    $client->request(
      'POST',
      $fullUrl,
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode($body),
    );

    $response = $client->getResponse();

    return [
      'status' => $response->getStatusCode(),
      'body' => $response->getContent(),
    ];
  }

  /**
   * Parses SSE events from a raw response body string.
   *
   * Each SSE frame is a "data: <json>\n\n" block. This method splits
   * the body, decodes the JSON, and returns structured event data.
   *
   * @param string $body
   *   The raw SSE response body.
   *
   * @return array
   *   Array of parsed event arrays, each with a 'type' key.
   */
  protected function parseSseEvents(string $body): array {
    $events = [];
    $frames = preg_split('/\n\n+/', trim($body));

    foreach ($frames as $frame) {
      $frame = trim($frame);
      if ($frame === '') {
        continue;
      }

      $data = '';
      foreach (explode("\n", $frame) as $line) {
        if (str_starts_with($line, 'data: ')) {
          $data .= substr($line, 6);
        }
      }

      if ($data === '' || $data === '[DONE]') {
        continue;
      }

      $decoded = json_decode($data, TRUE);
      if (is_array($decoded) && isset($decoded['type'])) {
        $events[] = $decoded;
      }
    }

    return $events;
  }

}
