<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockResponse;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin chat action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/chat
 * with a mock AI provider and verifies the SSE response stream.
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure the agent test module is enabled (provides MockAiProvider).
    \Drupal::service('module_installer')
      ->install(['oe_ai_assistant_agent_test']);

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
    MockAiProvider::reset();
    $this->restoreConfiguration();
    parent::tearDown();
  }

  /**
   * Tests that a text response is streamed as SSE events.
   *
   * Verifies the full SSE lifecycle: start, start-step, text-delta
   * events with the LLM response text, finish-step, finish, [DONE].
   */
  public function testTextResponseStreamedAsSse(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      text: 'Hello from the drafting assistant.',
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Hi there.',
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

    // Verify mock provider was actually used (call log has an entry).
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log, 'Mock provider should have been called once.');

    // Verify the system prompt includes the agent config entity's prompt.
    $this->assertStringContainsString(
      'content drafting assistant',
      $log[0]['system_prompt'],
      'System prompt should come from the oe_drafting_router config entity.',
    );
  }

  /**
   * Tests that conversation history persists across turns.
   *
   * Both requests share the same threadId so history accumulates
   * on the server. Turn 2's LLM call should include the user
   * message from turn 1.
   */
  public function testConversationHistoryPersists(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $threadId = bin2hex(random_bytes(16));

    // Turn 1.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Got it, you want to write about climate.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'I want to write about climate change.',
      'threadId' => $threadId,
    ]);

    // Turn 2 with the same threadId.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Sure, focusing on EU policy.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Focus on EU policy please.',
      'threadId' => $threadId,
    ]);

    // Check that turn 2's LLM call includes both messages.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two LLM calls should have been made.');

    $turn2Messages = $log[1]['messages'];
    $turn2Texts = array_column($turn2Messages, 'text');

    $this->assertContains(
      'I want to write about climate change.',
      $turn2Texts,
      'Turn 2 should include turn 1 user message in history.',
    );
    $this->assertContains(
      'Focus on EU policy please.',
      $turn2Texts,
      'Turn 2 should include the current user message.',
    );
  }

  /**
   * Tests session-backed chat persists the thread ID in plugin state.
   */
  public function testSessionBackedConversationUsesPluginStateThreadId(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    /** @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session */
    $session = \Drupal::entityTypeManager()
      ->getStorage('ai_editorial_session')
      ->create([
        'type' => 'content_creation',
        'uid' => $user->id(),
        'content_type' => 'oe_news',
      ]);
    $session->save();

    MockAiProvider::enqueue(new MockResponse(
      text: 'Stored session thread.',
    ));
    $first = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Remember this in the session.',
      'sessionId' => (string) $session->id(),
      'threadId' => 'request-thread-1',
      'bundle' => 'oe_news',
      'entityTypeId' => 'node',
    ]);

    $this->assertEquals(200, $first['status'],
      'Expected 200 response. Body: ' . substr($first['body'], 0, 500));

    $firstThreadId = $this->extractThreadId($first['body']);
    $this->assertNotEmpty($firstThreadId);

    /** @var \Drupal\oe_ai_assistant\Service\AiEditorialSessionPluginStore $pluginStore */
    $pluginStore = \Drupal::service(AiEditorialSessionPluginStore::class);
    $pluginInstance = $pluginStore->loadForSession($session, 'drafting');
    $this->assertNotNull($pluginInstance);
    $this->assertSame($firstThreadId, $pluginInstance->getStateValue('threadId'));

    MockAiProvider::enqueue(new MockResponse(
      text: 'Continuing session thread.',
    ));
    $second = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Continue with the same session.',
      'sessionId' => (string) $session->id(),
      'threadId' => 'request-thread-2',
      'bundle' => 'oe_news',
      'entityTypeId' => 'node',
    ]);

    $this->assertEquals(200, $second['status'],
      'Expected 200 response. Body: ' . substr($second['body'], 0, 500));
    $this->assertSame($firstThreadId, $this->extractThreadId($second['body']));

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two LLM calls should have been made.');

    $turn2Texts = array_column($log[1]['messages'], 'text');
    $this->assertContains(
      'Remember this in the session.',
      $turn2Texts,
      'Turn 2 should include turn 1 user message from the stored session thread.',
    );
    $this->assertContains(
      'Continue with the same session.',
      $turn2Texts,
      'Turn 2 should include the current user message.',
    );
  }

  /**
   * Tests that a draft_content tool call emits data-drafted-fields.
   *
   * When the LLM calls the draft_content tool, the plugin should
   * emit a data-drafted-fields custom event with the field values
   * so the frontend can populate the content table.
   */
  public function testToolCallEmitsDraftedFields(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'draft_content',
            'arguments' => json_encode([
              'fields' => [
                'title' => [['value' => 'Test Title']],
                'body' => [['value' => '<p>Test body.</p>', 'format' => 'full_html']],
              ],
              'changed_fields' => ['title', 'body'],
            ]),
          ],
        ],
      ],
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Draft a news article about testing.',
      'bundle' => 'oe_news',
      'entityTypeId' => 'node',
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);

    // Find the data-drafted-fields event.
    $draftedEvents = array_filter(
      $events,
      fn($e) => $e['type'] === 'data-drafted-fields',
    );
    $this->assertNotEmpty($draftedEvents,
      'SSE must include a data-drafted-fields event.');

    // Verify the drafted fields contain the expected data.
    $draftedEvent = reset($draftedEvents);
    $fields = $draftedEvent['data'] ?? [];
    $this->assertArrayHasKey('title', $fields,
      'Drafted fields should include title.');
    $this->assertEquals('Test Title',
      $fields['title'][0]['value'] ?? '',
      'Title value should match the mock response.');
  }

  /**
   * Tests that an empty message returns a 400 error.
   */
  public function testEmptyMessageReturns400(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => '',
    ]);

    $this->assertEquals(400, $result['status']);
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

  /**
   * Extracts the thread ID emitted by the drafting SSE stream.
   */
  protected function extractThreadId(string $body): ?string {
    $events = $this->parseSseEvents($body);
    foreach ($events as $event) {
      if (($event['type'] ?? '') !== 'data-thread-id') {
        continue;
      }
      $threadId = $event['data']['threadId'] ?? NULL;
      return is_string($threadId) ? $threadId : NULL;
    }

    return NULL;
  }

}
