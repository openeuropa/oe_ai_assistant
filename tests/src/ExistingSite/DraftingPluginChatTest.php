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

    // Every agent event is streamed as a transient data part, not shown in
    // the transcript.
    $agentEvents = array_values(array_filter($events, fn($e) => $e['type'] === 'data-agent-event'));
    $this->assertNotEmpty($agentEvents, 'Agent events are streamed as data parts.');
    $this->assertSame('drafting', $agentEvents[0]['data']['agent']);
    $this->assertTrue($agentEvents[0]['transient']);
    $this->assertSame([], array_filter($this->getMessages($session), fn($m) => ($m['type'] ?? '') === 'agent'),
      'Agent events stay out of the transcript.');
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
   * Tests that draft_group calls run the sub-agents and version the draft.
   *
   * The target content type comes from the session, not the request body.
   * Each group call streams its own tool call and result, the result of the
   * last group carries the versioned draft, and each sub-agent's system
   * prompt and answer are recorded nested under the calling turn.
   */
  public function testDraftGroupCallsRunSubAgents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->enqueueDraftFlow();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);

    // One tool call per group, in the order the model requested them.
    $groupCalls = array_values(array_filter(
      $events,
      fn($e) => $e['type'] === 'tool-call-start' && $e['toolName'] === 'draft_group',
    ));
    $this->assertSame(['call_1', 'call_2'], array_column($groupCalls, 'toolCallId'));

    // Each result names its group; the last one carries the versioned draft.
    $results = array_values(array_filter(
      $events,
      fn($e) => $e['type'] === 'tool-result' && isset($e['result']['group']),
    ));
    $this->assertSame(
      ['main_fields', 'field_content_paragraphs'],
      array_column(array_column($results, 'result'), 'group'),
    );
    $this->assertArrayNotHasKey('draft', $results[0]['result']);
    $this->assertSame(['field_content_paragraphs'], $results[0]['result']['pending']);
    $draft = $results[1]['result']['draft'];
    $this->assertSame(1, $draft['version']);
    $this->assertArrayHasKey('title', $draft['fields'],
      'Consolidated fields should include title.');

    // The model's answer after the tools is streamed as text.
    $text = implode('', array_map(fn($e) => $e['textDelta'] ?? '', $events));
    $this->assertStringContainsString('Draft 1 is ready', $text);

    // The sub-agent transcript is recorded: the calling turn has one system
    // row per group nested under it, followed by the assistant rows.
    $draftNode = $this->findDraftTurn($session);
    $this->assertNotNull($draftNode,
      'The draft_group turn is recorded as a root turn.');
    $calls = $draftNode['message']->getToolCalls();
    $this->assertCount(2, $calls);
    $this->assertSame(1, $calls[1]['result']['draft']['version'],
      'The versioned draft is stored on the completing call.');

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
    $this->assertGreaterThanOrEqual($systemCount, $assistantCount,
      'Each sub-agent records its system row and at least one answer.');

    // Each sub-agent row carries the agent id (the schema group id), and
    // each group records its system prompt exactly once.
    $agentIds = [];
    foreach ($draftNode['children'] as $child) {
      $agentId = $child['message']->get('agent_id')->value;
      $this->assertNotEmpty($agentId, 'Sub-agent rows carry an agent id.');
      $agentIds[$agentId] = TRUE;
    }
    $this->assertCount($systemCount, $agentIds,
      'One system row is recorded per sub-agent run.');
  }

  /**
   * Tests that every rejected answer reaches the stream as an error.
   *
   * The drafter is corrected until its answer matches the schema, and the
   * editor sees one error event per rejection, carrying the validator's
   * own lines.
   */
  public function testEveryRejectedAnswerIsStreamedAsAnError(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
        ],
      ],
    ));
    // Two answers naming a property the schema forbids, then a good one.
    MockAiProvider::enqueue(new MockResponse(text: '{"title": [{"value": "T"}], "body": [{"value": "B"}]}'));
    MockAiProvider::enqueue(new MockResponse(text: '{"title": [{"value": "T"}], "body": [{"value": "B"}]}'));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "T"}], "field_teaser": [{"value": "S"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'The main fields are drafted.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Draft the main fields.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    $rejections = array_values(array_filter(
      $this->parseSseEvents($result['body']),
      fn($e) => $e['type'] === 'data-agent-event' && ($e['data']['level'] ?? '') === 'error',
    ));
    $this->assertCount(2, $rejections,
      'One error event per rejected answer.');
    foreach ($rejections as $rejection) {
      $this->assertStringContainsString('answer rejected by the main_fields schema', $rejection['data']['summary']);
      $this->assertStringContainsString('The property body is not defined', $rejection['data']['summary']);
      $this->assertSame('main_fields', $rejection['data']['agent']);
    }
  }

  /**
   * Tests that a revision reuses the stored draft and groups under it.
   *
   * The named group is drafted again, every other group is carried over,
   * the new version records the draft it revises, and the history numbers
   * it as a revision of that draft.
   */
  public function testReviseDraftVersionsUnderTheDraftItRevises(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->enqueueDraftFlow();
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);

    // The agent revises the main fields of the latest draft.
    $this->enqueueRevision(['instruction' => 'Make the title shorter.', 'groups' => ['main_fields']]);
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Short"}], "field_teaser": [{"value": "Test teaser."}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.1 is ready.'));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Make the title shorter.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // The revision drafted one group only, after the two of the first turn.
    $this->assertSame(3, $this->drafterCallCount(),
      'Only the revised group is drafted again.');

    $drafts = $this->loadDraftResults($session);
    $this->assertCount(2, $drafts);
    $this->assertSame(2, $drafts[1]['version']);
    $this->assertSame(1, $drafts[1]['revisionOf'],
      'The revision records the draft it started from.');
    $this->assertSame('Short', $drafts[1]['fields']['title'][0]['value']);
    $this->assertSame(
      $drafts[0]['fields']['field_content_paragraphs'],
      $drafts[1]['fields']['field_content_paragraphs'],
      'A group left alone is carried over untouched.',
    );
    $this->assertSame($drafts[0]['context'], $drafts[1]['context'],
      'The revision inherits the context that produced the draft.');

    // The history numbers the revision under the draft it revises.
    /** @var \Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface $history */
    $history = \Drupal::service('Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface');
    \Drupal::entityTypeManager()->getStorage('ai_conversation_message')->resetCache();
    $this->assertSame(['1.0', '1.1'], array_column($history->listDrafts($session), 'label'));
    $this->assertSame(['Draft 1.0', 'Draft 1.1'], array_column($history->listDrafts($session), 'name'));
  }

  /**
   * Tests that a revision without named groups covers the whole draft.
   *
   * The session points at a template with one group by then, but the draft
   * being revised has two, so both are drafted again.
   */
  public function testReviseWithoutGroupsCoversTheWholeDraft(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->enqueueDraftFlow();
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->httpPost('/api/ai/plugins/drafting/set-template', [
      'sessionId' => $session->id(),
      'template' => 'news_default',
    ]);

    // The agent asks for a change to the whole draft, naming no group.
    $this->enqueueRevision(['instruction' => 'Append FOO to every field.', 'version' => 1]);
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title FOO"}], "field_teaser": [{"value": "Test teaser. FOO"}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_content_paragraphs": [{"type": [{"target_id": "text_block"}]}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.1 is ready.'));

    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Append FOO to every field of draft 1.',
      'sessionId' => $session->id(),
    ]);

    // Both groups of the draft were drafted again: the two of the first
    // turn plus two more.
    $this->assertSame(4, $this->drafterCallCount(),
      'Every group of the revised draft is drafted again.');

    $drafts = $this->loadDraftResults($session);
    $this->assertCount(2, $drafts);
    $this->assertSame('Test Title FOO', $drafts[1]['fields']['title'][0]['value']);
    $this->assertNotSame(
      $drafts[0]['fields']['field_content_paragraphs'],
      $drafts[1]['fields']['field_content_paragraphs'],
      'The paragraphs of the revised draft are drafted again too.',
    );
  }

  /**
   * Tests that a revision keeps the structure its draft was written with.
   *
   * The session points at another template by the time the revision runs.
   * The draft carries the groups it was written against, so the revised
   * group keeps its own fields and the groups it never had stay out.
   */
  public function testReviseUsesTheGroupsTheDraftWasWrittenWith(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    // The first draft follows the latest template, with a paragraphs group.
    $this->enqueueDraftFlow();
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);

    // The editor then switches to a template whose main fields also carry
    // field_body and which has no paragraphs group at all.
    $switch = $this->httpPost('/api/ai/plugins/drafting/set-template', [
      'sessionId' => $session->id(),
      'template' => 'news_default',
    ]);
    $this->assertEquals(200, $switch['status'],
      'Expected the template switch to succeed. Body: ' . substr($switch['body'], 0, 300));

    // Point the stored draft at the new template while keeping the groups
    // it was written with, so only the stored groups can explain the
    // revision that follows.
    $this->repointDraftTemplate($session, 'news_default');

    $this->enqueueRevision(['instruction' => 'Make the title shorter.', 'groups' => ['main_fields']]);
    // Answering the original main fields: against the session's current
    // template this would miss field_body and be sent back for a retry.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Short"}], "field_teaser": [{"value": "Test teaser."}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1.1 is ready.'));

    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Make the title shorter.',
      'sessionId' => $session->id(),
    ]);

    $this->assertSame(3, $this->drafterCallCount(),
      'The answer matched the original schema, so no correction was needed.');

    $drafts = $this->loadDraftResults($session);
    $this->assertCount(2, $drafts);
    $this->assertSame('Short', $drafts[1]['fields']['title'][0]['value']);
    $this->assertArrayHasKey('field_content_paragraphs', $drafts[1]['fields'],
      'The revision keeps the group the draft was written with.');
    $this->assertArrayNotHasKey('field_body', $drafts[1]['fields'],
      'A field the new template added never enters the revision.');
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
    $this->assertContains('draft_group', $toolNames);
  }

  /**
   * Tests that the resolved tone prompt reaches every sub-agent.
   *
   * The recorded sub-agent system rows nested under the draft_group turn
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

    // The agent calls draft_group per group, then one drafter answer each.
    $this->enqueueDraftFlow();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // Find the draft_group turn and inspect its nested system rows.
    $draftNode = $this->findDraftTurn($session);
    $this->assertNotNull($draftNode, 'A draft_group turn is recorded.');

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

    // The agent drafts every group, then closes with a text answer.
    $this->enqueueDraftFlow();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 500));

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $agentPrompt = $log[0]['system_prompt'];
    $this->assertStringContainsString("### $processedTitle (file: $processedTitle)\nAlpha briefing content for the draft.", $agentPrompt);
    $this->assertStringContainsString("### $pendingTitle (file: $pendingTitle)\nNot processed yet", $agentPrompt);
    $this->assertStringContainsString('wait a moment', $agentPrompt);

    // Every call after the first carries the same block.
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

    $this->seedMessage($session, 'user', 'Draft a news article.', [], (int) $user->id());
    $this->seedMessage($session, 'assistant', 'Here is a draft.');
    // A tool row is not user-visible and must be filtered out.
    $this->seedMessage($session, 'tool', 'Tool payload.');

    // Events are exercised in EditorialEventsTest; filter here.
    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    // Timestamps must come from the persisted rows' created field.
    $rows = array_values(array_filter(
      $this->loadTranscript($session),
      fn($row) => in_array($row->getRole(), ['user', 'assistant'], TRUE),
    ));

    $this->assertSame(
      [
        [
          'role' => 'user',
          'content' => 'Draft a news article.',
          'at' => $rows[0]->get('created')->date->format('c'),
          'userId' => (string) $user->id(),
          'userName' => $user->getDisplayName(),
        ],
        [
          'role' => 'assistant',
          'content' => 'Here is a draft.',
          'at' => $rows[1]->get('created')->date->format('c'),
        ],
      ],
      $messages,
    );
  }

  /**
   * Tests that get-messages surfaces a draft_group tool call and result.
   *
   * The drafted fields are stored as the result of the draft_group call so
   * the transcript can render a clickable trace that repopulates the artifact.
   */
  public function testGetMessagesReturnsDraftToolCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->seedMessage($session, 'user', 'Draft a news article.');
    // An assistant turn that drafted: empty text, a draft_group tool call
    // carrying the group fields as its result.
    $fields = ['group' => 'main_fields', 'fields' => ['title' => [['value' => 'Test Title']]]];
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
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
    $this->assertSame('draft_group', $messages[1]['toolCalls'][0]['function']['name']);
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

    // The version reaches the model through the tool result it answers to.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $lastCall = end($log);
    $toolTexts = array_column(array_filter($lastCall['messages'], fn($m) => $m['role'] === 'tool'), 'text');
    $this->assertNotEmpty(array_filter(
      $toolTexts,
      fn($t) => str_contains($t, '"version":1'),
    ), 'The completing tool result must name the draft version.');

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
        'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
        'result' => [
          'group' => 'main_fields',
          'draft' => [
            'version' => 1,
            'major' => 1,
            'minor' => 0,
            'context' => [
              'tone' => ['id' => '1', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'],
              'template' => ['id' => 'news_default', 'label' => 'News default'],
              'documents' => $documents,
            ],
            'fields' => ['title' => [['value' => 'First']]],
          ],
        ],
      ],
    ]);
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
        'result' => [
          'group' => 'main_fields',
          'draft' => [
            'version' => 2,
            'major' => 2,
            'minor' => 0,
            'context' => [
              'tone' => ['id' => '2', 'label' => 'Technical', 'prompt' => 'Use domain-specific terminology precisely.'],
              'template' => ['id' => 'news_default', 'label' => 'News default'],
              'documents' => [],
            ],
            'fields' => ['title' => [['value' => 'Second']]],
          ],
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
   * Rewrites the template a stored draft names, keeping its groups.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $templateId
   *   The template id to record on every stored draft.
   */
  protected function repointDraftTemplate(AiEditorialSessionInterface $session, string $templateId): void {
    foreach ($this->loadTranscript($session) as $message) {
      $calls = $message->getToolCalls();
      $changed = FALSE;
      foreach ($calls as &$call) {
        if (isset($call['result']['draft']['context']['template']['id'])) {
          $call['result']['draft']['context']['template']['id'] = $templateId;
          $changed = TRUE;
        }
      }
      unset($call);
      if ($changed) {
        $message->setToolCalls($calls);
        $message->save();
      }
    }
  }

  /**
   * Enqueues a full mock draft flow.
   *
   * The agent calls draft_group for the two groups of the session's
   * template (news_with_paragraphs) in one turn, each drafter answers once,
   * and the agent closes with a text answer.
   */
  protected function enqueueDraftFlow(): void {
    $calls = [];
    foreach (['main_fields', 'field_content_paragraphs'] as $index => $group) {
      $calls[] = [
        'id' => 'call_' . ($index + 1),
        'type' => 'function',
        'function' => ['name' => 'draft_group', 'arguments' => json_encode(['group' => $group])],
      ];
    }
    MockAiProvider::enqueue(new MockResponse(toolCalls: $calls));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title"}], "field_teaser": [{"value": "Test teaser."}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(text: '{"field_content_paragraphs": []}'));
    MockAiProvider::enqueue(new MockResponse(text: 'Draft 1 is ready. Review it on the right.'));
  }

  /**
   * Enqueues a revise_draft request from the mock agent.
   *
   * @param array $arguments
   *   The tool arguments: instruction, and optionally groups and version.
   */
  protected function enqueueRevision(array $arguments): void {
    MockAiProvider::enqueue(new MockResponse(toolCalls: [
      [
        'id' => 'call_r1',
        'type' => 'function',
        'function' => ['name' => 'revise_draft', 'arguments' => json_encode($arguments)],
      ],
    ]));
  }

  /**
   * Counts the calls made to the drafter sub-agents so far.
   */
  protected function drafterCallCount(): int {
    \Drupal::state()->resetCache();
    return count(array_filter(
      MockAiProvider::getCallLog(),
      fn($call) => str_contains($call['system_prompt'], 'You are a content generator'),
    ));
  }

  /**
   * Finds the root turn carrying the draft_group calls, with its children.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array|null
   *   The tree node, or NULL when no turn drafted.
   */
  protected function findDraftTurn(AiEditorialSessionInterface $session): ?array {
    $storage = \Drupal::entityTypeManager()->getStorage('ai_conversation_message');
    $storage->resetCache();
    $draftNode = NULL;
    foreach ($storage->loadTree($session) as $node) {
      foreach ($node['message']->getToolCalls() as $call) {
        if (($call['function']['name'] ?? '') === 'draft_group') {
          $draftNode = $node;
        }
      }
    }
    return $draftNode;
  }

  /**
   * Loads every versioned draft on the transcript, in order.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   The drafts shaped {version, context, fields, revisionOf}.
   */
  protected function loadDraftResults(AiEditorialSessionInterface $session): array {
    $results = [];
    foreach ($this->loadTranscript($session) as $message) {
      foreach ($message->getToolCalls() as $call) {
        if (isset($call['result']['draft'])) {
          $results[] = $call['result']['draft'];
        }
      }
    }
    return $results;
  }

}
