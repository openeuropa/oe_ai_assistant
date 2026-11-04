<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;

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
   * Tests that a text response is correctly framed SSE and persisted.
   *
   * The response is streamed as correctly framed SSE and persisted to the
   * agent's own thread on the session.
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

    // Every frame is exactly one "data: <json>\n\n" block; a frame whose data
    // decodes to something other than a JSON object means SSEEncoder's output
    // was handed to something that re-framed it (double-wrapping), which this
    // guards against directly rather than relying on parseSseEvents() finding
    // nothing.
    $frames = array_values(array_filter(
      preg_split('/\n\n/', $result['body']),
      fn(string $frame): bool => $frame !== '',
    ));
    $this->assertNotEmpty($frames, 'The stream must contain at least one frame.');
    $this->assertSame('data: [DONE]', end($frames), 'The stream must end with the data: [DONE] sentinel.');
    foreach (array_slice($frames, 0, -1) as $frame) {
      $this->assertStringStartsWith('data: ', $frame);
      $decoded = json_decode(substr($frame, strlen('data: ')), TRUE);
      $this->assertIsArray($decoded, 'Each non-terminal frame must decode to a single JSON object: ' . $frame);
      $this->assertArrayHasKey('type', $decoded);
    }

    $events = $this->parseSseEvents($result['body']);
    $types = array_column($events, 'type');
    // Exactly once each: the channel delivers every stream event in
    // addition to this method's own driving of the generator, so a frame
    // written twice (once directly, once through the channel) would
    // double these counts without necessarily breaking the looser
    // "contains" checks the rest of this test uses.
    $this->assertSame(1, count(array_filter($types, static fn ($t) => $t === 'start')),
      'SSE must include exactly one start event, not one per delivery path.');
    $this->assertSame(1, count(array_filter($types, static fn ($t) => $t === 'finish')),
      'SSE must include exactly one finish event, not one per delivery path.');

    $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
    $fullText = implode('', array_map(fn($e) => $e['delta'] ?? '', $textDeltas));
    $this->assertStringContainsString('Hello', $fullText,
      'Streamed text should contain the mock response.');

    // The turn is persisted on the agent's own Neuron thread for the session.
    $threads = $session->conversationThreads();
    $thread = $threads['drafting.session_' . $session->id()] ?? [];
    $roles = array_map(fn($row) => $row->get('role')->value, $thread);
    $this->assertContains('user', $roles, 'A user turn must be persisted.');
    $this->assertContains('assistant', $roles, 'An assistant turn must be persisted.');
  }

  /**
   * Tests that drafting reports per-group progress and the drafted fields.
   *
   * The drafting tool call writes every group of the template in one call,
   * so the stream would otherwise carry one tool part at the start and the
   * next minutes later; the channel is what reports progress in between.
   */
  public function testDraftingReportsPerGroupProgressAndDraftedFields(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>B</p>"}],'
        . ' "type": [{"target_id": "text_block"}]}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is ready.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Please draft it, go ahead.',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);
    $planEvents = array_values(array_filter($events, fn($e) => $e['type'] === 'data-plan'));
    // Exactly one initial all-pending frame, plus one in_progress and one
    // done per group (two groups): 5. An exact count, not a floor, so a
    // regression that delivers every frame twice (confirmed possible: the
    // channel and this method's own stream iteration are two independent
    // delivery paths) is caught here rather than only visible as a UI bug.
    $this->assertCount(5, $planEvents,
      'Exactly one pending frame plus one in_progress and one done per group.');

    // Every plan frame carries {stepId, label, status} per step, matching
    // what app/src/plugins/drafting/components/plan-steps.tsx expects.
    $statusesByStep = [];
    foreach ($planEvents as $event) {
      foreach ($event['data'] as $step) {
        $this->assertArrayHasKey('stepId', $step);
        $this->assertArrayHasKey('label', $step);
        $this->assertArrayHasKey('status', $step);
        $statusesByStep[$step['stepId']][] = $step['status'];
      }
    }
    $this->assertArrayHasKey('main_fields', $statusesByStep);
    $this->assertSame(['pending', 'in_progress', 'done'], array_values(array_unique($statusesByStep['main_fields'])));

    // Exactly one data-drafted-fields frame, after the last data-plan frame,
    // carrying the whole assembled field map.
    $draftedFieldsEvents = array_values(array_filter($events, fn($e) => $e['type'] === 'data-drafted-fields'));
    $this->assertCount(1, $draftedFieldsEvents, 'Exactly one data-drafted-fields frame.');
    $lastPlanIndex = array_search(end($planEvents), $events, TRUE);
    $draftedFieldsIndex = array_search($draftedFieldsEvents[0], $events, TRUE);
    $this->assertGreaterThan($lastPlanIndex, $draftedFieldsIndex,
      'data-drafted-fields must arrive after the last data-plan frame.');
    $this->assertSame([
      'title' => [['value' => 'T']],
      'field_teaser' => [['value' => 'S']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $draftedFieldsEvents[0]['data']);
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
   * Tests that document extracts reach the router and the sub-agents.
   *
   * One document is processed through the real Tika service, one is left
   * scheduled: the prompts must carry the extracted text of the first and
   * announce the second as not available yet.
   */
  public function testDocumentExtractsReachRouterAndSubAgents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    [$processedId, $processedTitle] = $this->uploadDocument($session, 'briefing.txt', 'Alpha briefing content for the draft.');
    [, $pendingTitle] = $this->uploadDocument($session, 'pending.txt', 'Not processed.');
    MockAiProvider::enqueue(new MockResponse(text: 'Briefing summary.'));
    $result = $this->httpPost('/api/ai/plugins/drafting/extract-document', [
      'sessionId' => $session->id(),
      'category' => 'context',
      'documentId' => $processedId,
    ]);
    $this->assertSame(['status' => 'done'], json_decode($result['body'], TRUE));
    MockAiProvider::reset();

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
    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 500));

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $router = $log[0]['system_prompt'];
    $this->assertStringContainsString("### $processedTitle (file: $processedTitle)\nAlpha briefing content for the draft.", $router);
    $this->assertStringContainsString("### $pendingTitle (file: $pendingTitle)\nNot processed yet", $router);
    $this->assertStringContainsString('wait a moment', $router);

    // Every sub-agent call after the router carries the same block.
    $this->assertGreaterThan(1, count($log));
    foreach (array_slice($log, 1) as $call) {
      $this->assertStringContainsString('Alpha briefing content for the draft.', $call['system_prompt']);
      $this->assertStringContainsString('Not processed yet', $call['system_prompt']);
    }
  }

  /**
   * Uploads a context document and returns its id and stored title.
   *
   * The title is read back because core renames an upload whose file name
   * already exists in the private directory.
   *
   * @return array
   *   The document id and title.
   */
  private function uploadDocument($session, string $filename, string $contents): array {
    $query = http_build_query(['sessionId' => $session->id(), 'category' => 'context', 'filename' => $filename]);
    $added = $this->httpPostRaw('/api/ai/plugins/drafting/add-document?' . $query, $contents);
    $this->assertSame(200, $added['status'], $added['body']);
    $document = json_decode($added['body'], TRUE)['document'];
    $media = \Drupal::entityTypeManager()->getStorage('media')->load($document['id']);
    $this->markEntityForCleanup($media);
    $this->markEntityForCleanup($media->get('oe_ai_context_document')->entity);

    return [(string) $document['id'], (string) $document['title']];
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
    $thread = 'drafting.session_' . $session->id();

    $userRow = $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'content' => json_encode([['type' => 'text', 'content' => 'Draft a news article.', 'meta' => []]]),
      'uid' => (int) $user->id(),
    ]);
    $assistantRow = $this->seedNeuronRow($session, $thread, [
      'role' => 'assistant',
      'content' => json_encode([['type' => 'text', 'content' => 'Here is a draft.', 'meta' => []]]),
    ]);
    // A row of a sub-agent's own thread is not the editor's transcript and
    // must be filtered out.
    $this->seedNeuronRow($session, 'field_group.session_' . $session->id() . '.group_main_fields', [
      'role' => 'user',
      'content' => json_encode([['type' => 'text', 'content' => 'leaked sub-agent chatter', 'meta' => []]]),
    ]);

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    $this->assertSame(
      [
        [
          'role' => 'user',
          'content' => 'Draft a news article.',
          'at' => date('c', (int) $userRow->get('created')->value),
          'userId' => (string) $user->id(),
          'userName' => $user->getDisplayName(),
        ],
        [
          'role' => 'assistant',
          'content' => 'Here is a draft.',
          'at' => date('c', (int) $assistantRow->get('created')->value),
        ],
      ],
      $messages,
    );
  }

  /**
   * Seeds a neuron_message row hosted by the session, under a thread.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session the row is stamped with.
   * @param string $threadId
   *   The thread the row belongs to.
   * @param array $values
   *   Field values, overriding the defaults below.
   *
   * @return \Drupal\ai_neuron\Entity\NeuronMessageInterface
   *   The saved row.
   */
  private function seedNeuronRow(AiEditorialSessionInterface $session, string $threadId, array $values = []) {
    static $counter = 0;
    $counter++;

    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    $entity = $storage->create($values + [
      'bundle' => 'editorial_session',
      'oe_ai_session' => (int) $session->id(),
      'thread_id' => $threadId,
      'message_id' => 'msg_' . $counter,
      'agent_id' => 'drafting',
      'role' => 'user',
      'turn' => $counter,
      'complete' => TRUE,
      'created' => \Drupal::time()->getCurrentTime(),
    ]);
    $entity->save();
    $storage->resetCache([$entity->id()]);

    // The 'created' field's computed date property is empty on the entity
    // create() just built, even after save(); reloading is what later
    // callers read through too (conversationThreads() loads fresh rows).
    return $storage->load($entity->id());
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
    $thread = 'drafting.session_' . $session->id();

    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'content' => json_encode([['type' => 'text', 'content' => 'Draft a news article.', 'meta' => []]]),
      'uid' => (int) $user->id(),
    ]);
    // The settled call+result pair the tool writes: the bare {draft, version}
    // it settles with, which getMessages() must replace with the full
    // versioned shape the artifact rehydrates from.
    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          ['name' => 'drafting_draft_content', 'result' => ['draft' => 'Draft 1', 'version' => 1]],
        ],
      ]),
    ]);

    // The matching row in the drafts thread, as DraftContentNeuronTool
    // ::storeDraft() writes it.
    $fields = ['title' => [['value' => 'Test Title']]];
    $context = [
      'tone' => NULL,
      'template' => ['id' => 'news_with_paragraphs', 'label' => 'News with paragraphs'],
      'documents' => [],
    ];
    assert($session instanceof ContentCreationSessionInterface);
    \Drupal::service(MessageStoreInterface::class)->append($session->draftsThread(), new AssistantMessage(json_encode([
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'context' => $context,
      'fields' => $fields,
    ])));

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    $this->assertCount(2, $messages);
    $this->assertSame('user', $messages[0]['role']);
    $this->assertArrayNotHasKey('toolCalls', $messages[0]);

    $this->assertSame('assistant', $messages[1]['role']);
    $this->assertArrayHasKey('toolCalls', $messages[1]);
    $this->assertSame('drafting_draft_content', $messages[1]['toolCalls'][0]['function']['name']);
    $this->assertSame([
      'version' => 1,
      'context' => $context,
      'fields' => $fields,
    ], $messages[1]['toolCalls'][0]['result']);
  }

  /**
   * Tests that a settled call's own pending row is not rendered twice.
   *
   * The store is append-only: ToolNode commits the call and its result as
   * a pair, and the call's own row stays in the thread beside its result
   * row rather than being replaced. Without the suppression, this would
   * surface as the drafting card plus a second, permanently "running"
   * card for the same call.
   */
  public function testGetMessagesDoesNotDuplicateTheSettledCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $thread = 'drafting.session_' . $session->id();

    // The model's own call, committed first and never replaced.
    $this->seedNeuronRow($session, $thread, [
      'role' => 'assistant',
      'meta' => json_encode([
        'type' => 'tool_call',
        'tools' => [
          ['name' => 'drafting_draft_content', 'inputs' => []],
        ],
      ]),
    ]);
    // The settled pair, committed immediately after.
    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          ['name' => 'drafting_draft_content', 'result' => ['draft' => 'Draft 1', 'version' => 1]],
        ],
      ]),
    ]);

    assert($session instanceof ContentCreationSessionInterface);
    \Drupal::service(MessageStoreInterface::class)->append($session->draftsThread(), new AssistantMessage(json_encode([
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'context' => ['tone' => NULL, 'template' => NULL, 'documents' => []],
      'fields' => ['title' => [['value' => 'Test Title']]],
    ])));

    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    // One message for the pair, not two: the pending row's own card must
    // not appear beside the settled one.
    $this->assertCount(1, $messages);
    $this->assertArrayHasKey('toolCalls', $messages[0]);
    $this->assertArrayHasKey('result', $messages[0]['toolCalls'][0]);
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
   * The history is seeded directly, including a populated context documents
   * fixture, so the test does not depend on the drafting flow.
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
        'status' => 'done',
        'meta' => ['type' => 'pdf', 'size' => 1024],
        'category' => 'context',
        'summary' => 'Key figures on EU emissions.',
      ],
      [
        'id' => '15',
        'title' => 'Programme factsheet',
        'status' => 'done',
        'meta' => ['type' => 'docx', 'size' => 2048],
        'category' => 'context',
        'summary' => 'Funding lines and deadlines.',
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
