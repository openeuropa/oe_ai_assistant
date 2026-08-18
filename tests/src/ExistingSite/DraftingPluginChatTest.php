<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration tests for the DraftingPlugin chat action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/chat
 * with a mock AI provider and verifies the SSE response stream. The
 * conversation is scoped by an editorial session: history and turns
 * persist as ai_conversation_message rows hosted by the session.
 */
class DraftingPluginChatTest extends DraftingPluginTestBase {

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

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));
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
   * The target content type comes from the session, not the request body. Each
   * sub-agent's system prompt and answer are recorded as a nested pair under
   * the draft_content turn.
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

    // The sub-agent transcript is recorded: the draft_content turn has system
    // and assistant sub-agent rows nested under it, one pair per group.
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    $storage->resetCache();
    $draftNode = NULL;
    foreach ($storage->loadTree($session) as $node) {
      foreach ($node['message']->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content') {
          $draftNode = $node;
        }
      }
    }
    $this->assertNotNull($draftNode,
      'A draft_content turn is recorded as a root turn.');

    $childRoles = array_map(
      fn($child) => $child['message']->getRole(),
      $draftNode['children'],
    );
    $systemCount = count(array_filter($childRoles, fn($r) => $r === 'system'));
    $assistantCount = count(
      array_filter($childRoles, fn($r) => $r === 'assistant'),
    );
    $this->assertGreaterThan(0, $systemCount,
      'Sub-agent system prompts are recorded under the draft turn.');
    $this->assertSame($systemCount, $assistantCount,
      'Each sub-agent records both a system and an assistant row.');

    // Each sub-agent row carries the agent id (the schema group id).
    foreach ($draftNode['children'] as $child) {
      $this->assertNotEmpty($child['message']->get('agent_id')->value,
        'Sub-agent rows carry an agent id.');
    }
  }

  /**
   * Tests that the router system prompt is stable and tone-free.
   *
   * The router prompt carries role and capabilities only; the tone reaches
   * the sub-agents instead, and tone changes must not alter the router
   * prompt between turns.
   */
  public function testRouterSystemPromptIsStableAndToneFree(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);

    MockAiProvider::enqueue(new MockResponse(text: 'First reply.'));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Draft this with context.',
      'sessionId' => $session->id(),
    ]);

    // Change the tone and chat again: the router prompt must not change.
    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Technical'),
    ]);
    MockAiProvider::enqueue(new MockResponse(text: 'Second reply.'));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'And again.',
      'sessionId' => $session->id(),
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two router calls should have been made.');

    $this->assertStringNotContainsString(
      'Use professional, institutional language.',
      $log[0]['system_prompt'],
      'The tone prompt must not be injected into the router prompt.',
    );
    $this->assertSame(
      $log[0]['system_prompt'],
      $log[1]['system_prompt'],
      'The router prompt must be identical across tone changes.',
    );

    $toolNames = $this->extractToolNames($log[0]['tools']);
    $this->assertContains('draft_content', $toolNames);
  }

  /**
   * Tests that the resolved tone prompt reaches every sub-agent.
   *
   * The recorded sub-agent system rows nested under the draft_content turn
   * must contain the tone prompt text, proving the orchestrator injected the
   * editorial context into the agents that produce the field values.
   */
  public function testTonePromptReachesSubAgents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);

    // Router signals draft_content, then one response per schema group.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title"}], "field_body": [{"value": "<p>Body</p>", "format": "full_html"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: '{"field_contacts": []}'));
    MockAiProvider::enqueue(new MockResponse(text: '{"field_content_paragraphs": []}'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // Find the draft_content turn and inspect its nested system rows.
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    $storage->resetCache();
    $draftNode = NULL;
    foreach ($storage->loadTree($session) as $node) {
      foreach ($node['message']->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content') {
          $draftNode = $node;
        }
      }
    }
    $this->assertNotNull($draftNode, 'A draft_content turn is recorded.');

    $systemRows = array_filter(
      $draftNode['children'],
      fn($child) => $child['message']->getRole() === 'system',
    );
    $this->assertNotEmpty($systemRows, 'Sub-agent system rows are recorded.');
    foreach ($systemRows as $row) {
      $content = (string) $row['message']->get('content')->value;
      $this->assertStringContainsString(
        'Use professional, institutional language.',
        $content,
        'Every sub-agent system prompt must contain the tone prompt.',
      );
      $this->assertStringContainsString(
        'Tone: Formal',
        $content,
        'Every sub-agent system prompt must contain the tone label.',
      );
    }
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

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

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

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

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
   * Tests draft versioning and the immutable provenance snapshot.
   *
   * Two drafts with different tones must yield versions 1 and 2, each
   * carrying the tone that was active when it was generated; changing the
   * tone must not alter an already stored snapshot.
   */
  public function testDraftResultCarriesVersionAndSnapshot(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);
    $this->enqueueDraftFlow();
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);

    $drafts = $this->loadDraftResults($session);
    $this->assertCount(1, $drafts);
    $this->assertSame(1, $drafts[0]['version']);
    $this->assertSame('Formal', $drafts[0]['context']['tone']['label']);
    $this->assertSame(
      'Use professional, institutional language. Maintain a neutral, authoritative voice. Avoid contractions and colloquialisms.',
      $drafts[0]['context']['tone']['prompt'],
      'The snapshot must store the raw tone guidelines.',
    );
    $this->assertSame('news_with_paragraphs', $drafts[0]['context']['template']['id']);
    $this->assertSame([], $drafts[0]['context']['documents']);
    $this->assertArrayHasKey('title', $drafts[0]['fields']);

    // The version reaches the model through the recorded confirmation.
    $transcript = $this->loadTranscript($session);
    $texts = array_map(
      fn($m) => (string) $m->get('content')->value, $transcript,
    );
    $this->assertNotEmpty(array_filter(
      $texts,
      fn($t) => str_contains($t, 'Draft 1 generated with'),
    ), 'The confirmation must name the draft version.');

    // Second draft with a different tone.
    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Technical'),
    ]);
    $this->enqueueDraftFlow();
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate another draft.',
      'sessionId' => $session->id(),
    ]);

    $drafts = $this->loadDraftResults($session);
    $this->assertCount(2, $drafts);
    $this->assertSame(1, $drafts[0]['version']);
    $this->assertSame('Formal', $drafts[0]['context']['tone']['label'],
      'The first snapshot must not change when the tone changes.');
    $this->assertSame(2, $drafts[1]['version']);
    $this->assertSame('Technical', $drafts[1]['context']['tone']['label']);
    $this->assertNotEmpty($drafts[1]['context']['tone']['prompt'],
      'The second snapshot must carry its own tone guidelines.');
  }

  /**
   * Tests that get_draft_history returns the drafts of the pinned session.
   *
   * The history is seeded directly, including a populated documents fixture
   * of both categories, so the test does not depend on the drafting flow.
   * The mock router calls the tool with a bogus session id to prove the
   * fixed tool context pins the real one.
   */
  public function testGetDraftHistoryToolReturnsPinnedSessionDrafts(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    // Seed two drafted turns with provenance snapshots.
    $documents = [
      [
        'id' => '12',
        'title' => 'Climate briefing note',
        'category' => 'context',
        'summary' => 'Key figures on EU emissions.',
        'meta' => ['mime' => 'application/pdf'],
      ],
      [
        'id' => '15',
        'title' => 'Hero image',
        'category' => 'publishable',
        'summary' => 'Wind turbines at sunset.',
        'meta' => ['mime' => 'image/png'],
      ],
    ];
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => [
          'version' => 1,
          'context' => [
            'tone' => ['id' => '1', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'],
            'template' => ['id' => 'news_default', 'label' => 'News default'],
            'documents' => $documents,
          ],
          'fields' => ['title' => [['value' => 'First']]],
        ],
      ],
    ]);
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        'result' => [
          'version' => 2,
          'context' => [
            'tone' => ['id' => '2', 'label' => 'Technical', 'prompt' => 'Use domain-specific terminology precisely.'],
            'template' => ['id' => 'news_default', 'label' => 'News default'],
            'documents' => [],
          ],
          'fields' => ['title' => [['value' => 'Second']]],
        ],
      ],
    ]);

    // The router calls the tool with a bogus session id, then answers.
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => [
            'name' => 'get_draft_history',
            'arguments' => '{"session_id": "999999"}',
          ],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: 'Draft 2 used the Technical tone.',
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Which tone produced draft 2?',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // The tool result was recorded as a tool row scoped to OUR session.
    $toolRows = array_values(array_filter(
      array_map(
        fn($n) => $n['message'],
        \Drupal::entityTypeManager()
          ->getStorage('ai_conversation_message')->loadTree($session),
      ),
      fn($m) => $m->getRole() === 'tool',
    ));
    $this->assertNotEmpty($toolRows, 'The tool result must be recorded.');
    $payload = (string) $toolRows[0]->get('content')->value;
    $this->assertStringContainsString('Draft 1', $payload);
    $this->assertStringContainsString('Draft 2', $payload);
    $this->assertStringContainsString('Formal', $payload);
    $this->assertStringContainsString('Technical', $payload);
    $this->assertStringContainsString('Climate briefing note', $payload,
      'Populated document descriptors must flow through the tool output.');
  }

  /**
   * Tests that persisted tool result rows are not replayed to the provider.
   *
   * A stored tool row cannot be re-linked to the assistant call that
   * produced it, and providers reject unpaired tool messages, so the
   * reconstructed history must skip tool rows entirely.
   */
  public function testHistoryReconstructionSkipsToolRows(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    // Seed a turn that used a tool: the assistant row carries the call,
    // the tool row carries the result, and a follow-up summarizes it.
    $this->seedMessage($session, 'user', 'Which tone produced draft 1?');
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'get_draft_history', 'arguments' => '{}'],
      ],
    ]);
    $this->seedMessage($session, 'tool', '{"drafts":[]}');
    $this->seedMessage($session, 'assistant', 'No drafts exist yet.');

    MockAiProvider::enqueue(new MockResponse(text: 'Noted.'));
    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Thanks.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status']);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $roles = array_column($log[0]['messages'], 'role');
    $this->assertNotContains('tool', $roles,
      'Persisted tool rows must not be replayed to the provider.');
    $this->assertContains('No drafts exist yet.',
      array_column($log[0]['messages'], 'text'),
      'The assistant summary of the tool outcome stays in the history.');
  }

  /**
   * Extracts tool names from the mock provider log.
   */
  protected function extractToolNames(array $tools): array {
    $names = [];
    foreach ($tools as $tool) {
      $names[] = $tool['function']['name'] ?? $tool['name'] ?? '';
    }

    return array_values(array_filter($names));
  }

  /**
   * Enqueues a full mock draft flow: router signal plus three sub-agents.
   */
  protected function enqueueDraftFlow(): void {
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'draft_content', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title"}], "field_body": [{"value": "<p>Body</p>", "format": "full_html"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: '{"field_contacts": []}'));
    MockAiProvider::enqueue(new MockResponse(text: '{"field_content_paragraphs": []}'));
  }

  /**
   * Loads every stored draft_content result for a session, in order.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   The result arrays stored on draft_content tool calls.
   */
  protected function loadDraftResults(AiEditorialSessionInterface $session): array {
    $results = [];
    foreach ($this->loadTranscript($session) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_content'
          && isset($call['result'])
        ) {
          $results[] = $call['result'];
        }
      }
    }
    return $results;
  }

}
