<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockResponse;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the agent test plugin with a mock AI provider.
 *
 * Validates that the plugin can stream LLM responses as SSE events
 * using the Vercel AI SDK UI Message Stream v1 protocol.
 */
class AgentTestPluginTest extends ExistingSiteBase {

  use ExistingSiteConfigBackupTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure the agent test module is enabled (provides the mock provider
    // and the agent_test plugin).
    \Drupal::service('module_installer')->install(['oe_ai_assistant_agent_test']);

    // Backup the AI settings and set mock_ai as default provider.
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
   * Tests that the mock provider logs system prompt and tools.
   */
  public function testCallLogCapturesSystemPromptAndTools(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      text: 'I can help with that.',
    ));

    $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Hello.',
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $this->assertArrayHasKey('system_prompt', $log[0]);
    $this->assertArrayHasKey('tools', $log[0]);
    $this->assertArrayHasKey('messages', $log[0]);
  }

  /**
   * Tests that the router LLM call includes the test_draft_content tool.
   */
  public function testRouterCallIncludesDraftContentTool(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      text: 'Sure, tell me more about what you want.',
    ));

    $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'I want to write about climate.',
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);

    // The router call should have the test_draft_content tool defined.
    $tools = $log[0]['tools'];
    $this->assertNotEmpty($tools, 'Router call should include tools.');
    $toolNames = array_column(array_column($tools, 'function'), 'name');
    $this->assertContains('test_draft_content', $toolNames);
  }

  /**
   * Tests multi-turn conversation with context building then drafting.
   *
   * Turn 1: user provides topic context.
   * Turn 2: user adds specifics.
   * Turn 3: user asks to draft -- sub-agents receive full history.
   */
  public function testMultiTurnConversationThenDraft(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    // Queue all responses upfront: 2 conversational + 1 tool call +
    // 3 sub-agent responses. They are consumed in order across the
    // 3 HTTP requests.
    // Turn 1 response: conversational text.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Got it, I can help with that topic.',
    ));
    // Turn 2 response: conversational text.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Understood, focusing on 2030 targets.',
    ));
    // Turn 3 response: tool call triggers orchestration.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'test_draft_content',
            'arguments' => json_encode([
              'instructions' => 'Draft about EU climate 2030 targets.',
            ]),
          ],
        ],
      ],
    ));
    // Sub-agent responses.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": "EU 2030 Targets", "summary": "Emissions goals."}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "hero", "heading": "2030 Goals", "body": "55% cut."}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "text_block", "heading": "Policies", "body": "Fit for 55."}',
    ));

    // Turn 1: user provides context.
    $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'I want to write about the EU climate deal.',
    ]);

    // Turn 2: user adds more context.
    $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Focus specifically on the 2030 emissions targets.',
    ]);

    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Draft it for me.',
    ]);

    $this->assertEquals(200, $response['status']);
    $events = $this->parseSseEvents($response['body']);

    // Should produce a consolidated draft.
    $draftedEvents = array_filter($events, fn($e) => $e['type'] === 'data-drafted-fields');
    $this->assertNotEmpty($draftedEvents, 'Expected data-drafted-fields event.');
    $drafted = array_values($draftedEvents)[0]['data'];
    $this->assertEquals('EU 2030 Targets', $drafted['title']);

    // Verify that sub-agents received conversation history.
    // The router call (turn 3) should have 5 messages in history:
    // turn 1 user, turn 1 assistant, turn 2 user, turn 2 assistant,
    // turn 3 user.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    // Third router call (index 2).
    $routerCall = $log[2];
    $this->assertCount(5, $routerCall['messages'],
      'Router should receive full 5-message conversation history.');

    // Sub-agent calls should include conversation context in their
    // task prompt (system prompt comes from config, user message is
    // the task which includes the conversation).
    // First sub-agent call (index 3).
    $subAgentCall = $log[3];
    $subAgentMsg = $subAgentCall['messages'][0]['text'] ?? '';
    $this->assertStringContainsString('EU climate deal', $subAgentMsg,
      'Sub-agent should receive conversation history as context.');
    $this->assertStringContainsString('2030 emissions targets', $subAgentMsg,
      'Sub-agent should see context from turn 2.');

    // All mock responses consumed.
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that a tool call triggers orchestration with sub-agent steps.
   */
  public function testToolCallTriggersOrchestration(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    // Queue: router returns tool call, then 3 sub-agent responses.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'test_draft_content',
            'arguments' => json_encode([
              'instructions' => 'Write about EU climate targets.',
            ]),
          ],
        ],
      ],
    ));
    // Main fields sub-agent response.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": "EU Climate Targets", "summary": "A summary of the 2030 goals."}',
    ));
    // Hero item sub-agent response.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "hero", "heading": "The 2030 Challenge", "body": "Hero body text."}',
    ));
    // Text block item sub-agent response.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "text_block", "heading": "Key Policies", "body": "Policy details."}',
    ));

    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Draft it for me.',
    ]);

    $this->assertEquals(200, $response['status']);
    $events = $this->parseSseEvents($response['body']);

    // Should have a data-plan event listing all steps upfront.
    $planEvents = array_filter($events, fn($e) => $e['type'] === 'data-plan');
    $this->assertNotEmpty($planEvents, 'Expected a data-plan event.');
    $plan = array_values($planEvents)[0]['data'];
    $this->assertCount(3, $plan);
    $this->assertEquals('main_fields', $plan[0]['stepId']);
    $this->assertEquals('pending', $plan[0]['status']);
    $this->assertEquals('item_hero', $plan[1]['stepId']);
    $this->assertEquals('item_text_block', $plan[2]['stepId']);

    // Should have multiple start-step/finish-step pairs (one per sub-agent).
    $startSteps = array_filter($events, fn($e) => $e['type'] === 'start-step');
    $finishSteps = array_filter($events, fn($e) => $e['type'] === 'finish-step');
    $this->assertGreaterThanOrEqual(3, count($startSteps), 'Expected at least 3 start-step events.');
    $this->assertGreaterThanOrEqual(3, count($finishSteps), 'Expected at least 3 finish-step events.');

    // Should end with a data-drafted-fields event containing the
    // consolidated JSON object.
    $draftedEvents = array_filter($events, fn($e) => $e['type'] === 'data-drafted-fields');
    $this->assertNotEmpty($draftedEvents, 'Expected a data-drafted-fields event.');
    $drafted = array_values($draftedEvents)[0]['data'];
    $this->assertEquals('EU Climate Targets', $drafted['title']);
    $this->assertEquals('A summary of the 2030 goals.', $drafted['summary']);
    $this->assertCount(2, $drafted['items']);
    $this->assertEquals('hero', $drafted['items'][0]['type']);
    $this->assertEquals('text_block', $drafted['items'][1]['type']);

    // All mock responses consumed.
    \Drupal::state()->resetCache();
    $this->assertTrue(MockAiProvider::isEmpty());

    // Verify that sub-agent calls used the config entity's system prompt.
    $log = MockAiProvider::getCallLog();
    $this->assertGreaterThanOrEqual(4, count($log));
    // Sub-agent calls (indices 1-3) should have the config entity's prompt.
    $this->assertStringContainsString('JSON', $log[1]['system_prompt']);
  }

  /**
   * Tests that the content drafter agent has no state leak between calls.
   */
  public function testSubAgentStateIsolation(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'test_draft_content',
            'arguments' => json_encode(['instructions' => 'Write content.']),
          ],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": "Title", "summary": "Summary"}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "hero", "heading": "Hero", "body": "Hero body"}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"type": "text_block", "heading": "Text", "body": "Text body"}',
    ));

    $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Draft it.',
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();

    // Sub-agent calls are log entries 1, 2, 3 (index 0 is the router).
    // Each call should have a clean system prompt (from config entity)
    // without accumulated state from previous calls.
    $this->assertGreaterThanOrEqual(4, count($log));

    // All sub-agent calls should share the same system prompt (from
    // config entity), proving no prompt accumulation.
    $subAgent1Prompt = $log[1]['system_prompt'];
    $subAgent2Prompt = $log[2]['system_prompt'];
    $subAgent3Prompt = $log[3]['system_prompt'];
    $this->assertEquals($subAgent1Prompt, $subAgent2Prompt, 'Sub-agent prompts should be identical (no state leak).');
    $this->assertEquals($subAgent2Prompt, $subAgent3Prompt, 'Sub-agent prompts should be identical (no state leak).');

    // Each call should have only one user message (the Task), not
    // accumulated messages from previous sub-agent calls.
    $this->assertCount(1, $log[1]['messages'], 'First sub-agent should have 1 message.');
    $this->assertCount(1, $log[2]['messages'], 'Second sub-agent should have 1 message.');
    $this->assertCount(1, $log[3]['messages'], 'Third sub-agent should have 1 message.');
  }

  /**
   * Tests graceful handling when a sub-agent fails.
   */
  public function testSubAgentFailureEmitsError(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    // Router calls test_draft_content.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'test_draft_content',
            'arguments' => json_encode(['instructions' => 'Test.']),
          ],
        ],
      ],
    ));
    // Main fields succeeds.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": "Test", "summary": "Sum"}',
    ));
    // Hero sub-agent fails.
    MockAiProvider::enqueue(new MockResponse(
      error: new \RuntimeException('LLM service unavailable'),
    ));

    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Draft it.',
    ]);

    $this->assertEquals(200, $response['status']);
    $events = $this->parseSseEvents($response['body']);

    // Should contain an error event.
    $errorEvents = array_filter($events, fn($e) => $e['type'] === 'error');
    $this->assertNotEmpty($errorEvents, 'Expected an error SSE event.');
  }

  /**
   * Tests that a text response does not trigger drafting.
   */
  public function testConversationalTurnWithoutDrafting(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    MockAiProvider::enqueue(new MockResponse(
      text: 'Got it, tell me more about the topic.',
    ));

    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'I want to write about renewable energy.',
    ]);

    $this->assertEquals(200, $response['status']);
    $events = $this->parseSseEvents($response['body']);

    // Should have text-delta events.
    $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
    $this->assertNotEmpty($textDeltas);

    // Should NOT have a data-drafted-fields event.
    $draftedEvents = array_filter($events, fn($e) => $e['type'] === 'data-drafted-fields');
    $this->assertEmpty($draftedEvents, 'Should not draft without tool call.');

    // Should have exactly 1 start-step (the router step only).
    $startSteps = array_filter($events, fn($e) => $e['type'] === 'start-step');
    $this->assertCount(1, $startSteps);

    \Drupal::state()->resetCache();
    $this->assertTrue(MockAiProvider::isEmpty());
  }

  /**
   * Tests that a simple text response is streamed as SSE text-delta events.
   */
  public function testTextResponseStreamedAsSse(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    // Queue a simple text response in the mock provider.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Hello from the mock LLM provider.',
    ));

    // POST to the agent_test plugin chat action.
    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Say hello.',
    ]);

    $this->assertEquals(200, $response['status']);

    // Parse SSE events from the response body.
    $events = $this->parseSseEvents($response['body']);

    // Should contain text-delta events with the streamed words.
    $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
    $this->assertNotEmpty($textDeltas, 'Expected text-delta SSE events.');

    // Reconstruct the full text from all text-delta events.
    $fullText = '';
    foreach ($textDeltas as $delta) {
      $fullText .= $delta['data']['textDelta'];
    }
    $this->assertEquals(
      'Hello from the mock LLM provider.',
      trim($fullText),
      'Reconstructed text should match the mock response.'
    );

    // Should end with a finish event.
    $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
    $this->assertNotEmpty($finishEvents, 'Expected a finish SSE event.');

    // The mock queue should be empty (all responses consumed).
    \Drupal::state()->resetCache();
    $this->assertTrue(MockAiProvider::isEmpty(), 'All mock responses should be consumed.');
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
   *   An array with 'status' and 'body' (raw string) keys.
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
   *   Array of parsed events, each with 'type' and 'data' keys.
   */
  protected function parseSseEvents(string $body): array {
    $events = [];
    // Split on double newlines (SSE frame boundary).
    $frames = preg_split('/\n\n+/', trim($body));

    foreach ($frames as $frame) {
      $frame = trim($frame);
      if ($frame === '') {
        continue;
      }

      // Extract data lines from the frame.
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
