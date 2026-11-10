<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration tests for the DraftingPlugin chat action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/chat
 * with a mock AI provider and verifies the SSE response stream. The
 * conversation is scoped by an editorial session: history and turns
 * persist as neuron_message rows on the agent's own thread.
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
    $thread = $threads['editorial.session_' . $session->id()] ?? [];
    $roles = array_map(fn($row) => $row->get('role')->value, $thread);
    $this->assertContains('user', $roles, 'A user turn must be persisted.');
    $this->assertContains('assistant', $roles, 'An assistant turn must be persisted.');
  }

  /**
   * Tests that drafting reports per-group progress and the drafted fields.
   *
   * One deferred call writes every group of the template. The stream would
   * otherwise carry one tool part at the start and the next minutes later.
   * The node that drafts yields an activity per group in between.
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

    // A frame that does not name its own call draws no draft card.
    // The card of a tool call reads its run under that call's id. The
    // editor then watches a plain tool call while the draft runs.
    // Activity frames report one group at a time, which leaves the editor
    // no view of how many groups remain. So the frame names the call it
    // answers, and lists every group as pending before the first activity
    // frame.
    $started = array_values(array_filter($events, fn($e) => $e['type'] === 'data-draft-started'));
    $this->assertCount(1, $started, 'Exactly one data-draft-started frame.');
    $this->assertSame(1, $started[0]['data']['version']);
    $this->assertSame('call_1', $started[0]['data']['callId']);
    $this->assertSame([
      ['id' => 'main_fields', 'label' => 'Main fields', 'status' => 'pending'],
      ['id' => 'field_content_paragraphs', 'label' => 'Content paragraphs', 'status' => 'pending'],
    ], $started[0]['data']['groups']);

    $activity = array_values(array_filter($events, fn($e) => $e['type'] === 'data-workflow-activity'));
    $this->assertGreaterThan(
      array_search($started[0], $events, TRUE),
      array_search($activity[0], $events, TRUE),
      'data-draft-started must arrive before the first activity frame.',
    );
    // One drafting frame and one drafted frame per group, for two groups: 4.
    // An exact count, not a floor, so a regression that delivers every frame
    // twice is caught here rather than only as a UI bug.
    $this->assertCount(4, $activity,
      'One drafting frame and one drafted frame per group.');

    // Every frame names its group, its label and its status, which is what
    // app/src/plugins/drafting/hooks/use-drafting-runtime.ts merges by group.
    $statusesByGroup = [];
    foreach ($activity as $event) {
      $step = $event['data']['data'];
      $this->assertArrayHasKey('group', $step);
      $this->assertArrayHasKey('label', $step);
      $this->assertArrayHasKey('status', $step);
      $statusesByGroup[$step['group']][] = $step['status'];
    }
    $this->assertArrayHasKey('main_fields', $statusesByGroup);
    $this->assertSame(['drafting', 'drafted'], $statusesByGroup['main_fields']);

    // Exactly one data-drafted-fields frame, after the last activity frame,
    // carrying the version, the editorial context snapshot and the whole
    // assembled field map — the same shape getMessages() joins onto the
    // tool-call result on reload, so the live stream and a reload show the
    // editor the same draft without a page refresh in between.
    $draftedFieldsEvents = array_values(array_filter($events, fn($e) => $e['type'] === 'data-drafted-fields'));
    $this->assertCount(1, $draftedFieldsEvents, 'Exactly one data-drafted-fields frame.');
    $lastActivityIndex = array_search(end($activity), $events, TRUE);
    $draftedFieldsIndex = array_search($draftedFieldsEvents[0], $events, TRUE);
    $this->assertGreaterThan($lastActivityIndex, $draftedFieldsIndex,
      'data-drafted-fields must arrive after the last activity frame.');
    $this->assertSame(1, $draftedFieldsEvents[0]['data']['version']);
    // The frame carries the whole draft, not the fields alone: the card the
    // editor is watching reads its groups and its status off this one, and a
    // key left out here reaches it as nothing at all.
    $this->assertSame('drafted', $draftedFieldsEvents[0]['data']['status']);
    $this->assertSame('', $draftedFieldsEvents[0]['data']['error']);
    $this->assertSame([
      ['id' => 'main_fields', 'label' => 'Main fields', 'status' => 'drafted'],
      ['id' => 'field_content_paragraphs', 'label' => 'Content paragraphs', 'status' => 'drafted'],
    ], $draftedFieldsEvents[0]['data']['groups']);
    $this->assertNotEmpty($draftedFieldsEvents[0]['data']['createdAt']);
    $this->assertArrayHasKey('template', $draftedFieldsEvents[0]['data']['context']);
    $this->assertSame(['tone' => NULL, 'documents' => []], [
      'tone' => $draftedFieldsEvents[0]['data']['context']['tone'],
      'documents' => $draftedFieldsEvents[0]['data']['context']['documents'],
    ]);
    $this->assertSame([
      'title' => [['value' => 'T']],
      'field_teaser' => [['value' => 'S']],
      'field_content_paragraphs' => [
        [
          'field_text_body' => [['value' => '<p>B</p>']],
          'type' => [['target_id' => 'text_block']],
        ],
      ],
    ], $draftedFieldsEvents[0]['data']['fields']);
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
   * Tests that the drafting agent's own system prompt is stable and tone-free.
   *
   * The agent's prompt carries role and capabilities only; the tone reaches
   * the field-group sub-agents instead, and tone changes must not alter the
   * drafting agent's own prompt between turns.
   */
  public function testDraftingAgentSystemPromptIsStableAndToneFree(): void {
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
    $this->assertCount(2, $log, 'Two drafting agent calls should have been made.');

    $this->assertStringNotContainsString(
      'Use professional, institutional language.',
      $log[0]['system_prompt'],
      "The tone prompt must not be injected into the drafting agent's own prompt.",
    );
    $this->assertSame(
      $log[0]['system_prompt'],
      $log[1]['system_prompt'],
      "The drafting agent's own prompt must be identical across tone changes.",
    );

    $toolNames = $this->extractToolNames($log[0]['tools']);
    $this->assertContains('drafting_draft_content', $toolNames);
  }

  /**
   * Tests that the resolved tone prompt reaches every field-group sub-agent.
   *
   * Each field-group drafter's own call to the provider must carry the
   * tone prompt, proving it reached the agents that produce the field
   * values rather than only the editor-facing drafting agent.
   */
  public function testTonePromptReachesSubAgents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);

    // The drafting agent signals drafting_draft_content, then one response
    // per schema group of the default oe_news template: main_fields and
    // field_content_paragraphs, same shape as DraftingTurnTest's kernel
    // fixture.
    $this->enqueueDraftFlow();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // Four provider calls for this turn: the drafting agent's own call that
    // triggers the tool, one per field-group sub-agent, and the drafting
    // agent's confirmation call. Only the two sub-agent calls (index 1, 2)
    // carry the tone; the drafting agent's own calls (index 0, 3) must not,
    // per testDraftingAgentSystemPromptIsStableAndToneFree.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(4, $log, 'One call per group plus the agent\'s own two calls.');

    foreach ([1, 2] as $subAgentCallIndex) {
      $this->assertStringContainsString(
        'Use professional, institutional language.',
        $log[$subAgentCallIndex]['system_prompt'],
        "Field-group sub-agent call $subAgentCallIndex must contain the tone prompt.",
      );
      $this->assertStringContainsString(
        'Tone: Formal',
        $log[$subAgentCallIndex]['system_prompt'],
        "Field-group sub-agent call $subAgentCallIndex must contain the tone label.",
      );
    }
  }

  /**
   * Tests that document extracts reach the field-group sub-agents.
   *
   * One document is processed through the real Tika service, one is left
   * scheduled: every field-group drafter's prompt must carry the extracted
   * text of the first and announce the second as not available yet.
   * EditorialNeuronAgent::instructions() does not call documentsPrompt() —
   * confirmed by reading it — so the editor-facing agent itself is not
   * asserted on here; only EditorialSessionAgentBase's sub-agents
   * (GroupDrafterNeuronAgent) call it.
   */
  public function testDocumentExtractsReachFieldGroupSubAgents(): void {
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

    $this->enqueueDraftFlow();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate the draft now.',
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status'], 'Expected 200. Body: ' . substr($result['body'], 0, 500));

    // Four calls: the drafting agent (index 0, 3) and the two field-group
    // sub-agents (index 1, 2). Only the sub-agent calls carry documents.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(4, $log);
    foreach ([1, 2] as $subAgentCallIndex) {
      $prompt = $log[$subAgentCallIndex]['system_prompt'];
      $this->assertStringContainsString("### $processedTitle (file: $processedTitle)\nAlpha briefing content for the draft.", $prompt);
      $this->assertStringContainsString("### $pendingTitle (file: $pendingTitle)\nNot processed yet", $prompt);
      $this->assertStringContainsString('wait a moment', $prompt);
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
    $thread = 'editorial.session_' . $session->id();

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
    $this->seedNeuronRow($session, 'group_drafter.session_' . $session->id() . '.group_main_fields', [
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
      'agent_id' => 'editorial',
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
    $thread = 'editorial.session_' . $session->id();

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

    // The matching row in the drafts thread, as DraftContentNode
    // ::storeDraft() writes it.
    $fields = ['title' => [['value' => 'Test Title']]];
    $context = [
      'tone' => NULL,
      'template' => ['id' => 'news_with_paragraphs', 'label' => 'News with paragraphs'],
      'documents' => [],
    ];
    $groups = [
      ['id' => 'main_fields', 'label' => 'Main fields', 'status' => 'drafted'],
      ['id' => 'content_paragraphs', 'label' => 'Content paragraphs', 'status' => 'drafted'],
    ];
    $draft = [
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'status' => 'drafted',
      'context' => $context,
      'groups' => $groups,
      'fields' => $fields,
    ];
    assert($session instanceof ContentCreationSessionInterface);
    $this->storeDraft($session, $draft);

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
    $this->assertSame($draft, $messages[1]['toolCalls'][0]['result']);
  }

  /**
   * Tests that a run that failed reaches the transcript the way it ended.
   *
   * The editor reads the same card during the run and after a reload, so the
   * record a failed run stores is joined onto its call. The run stored no
   * field, so the draft is not one the editor can open.
   */
  public function testGetMessagesReturnsFailedDraftRun(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $thread = 'editorial.session_' . $session->id();

    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          [
            'name' => 'drafting_draft_content',
            'result' => [
              'error' => 'The group could not be drafted.',
              'group' => 'content_paragraphs',
              'version' => 1,
            ],
          ],
        ],
      ]),
    ]);

    $context = [
      'tone' => NULL,
      'template' => ['id' => 'news_with_paragraphs', 'label' => 'News with paragraphs'],
      'documents' => [],
    ];
    $groups = [
      ['id' => 'main_fields', 'label' => 'Main fields', 'status' => 'drafted'],
      [
        'id' => 'content_paragraphs',
        'label' => 'Content paragraphs',
        'status' => 'failed',
        'error' => 'The group could not be drafted.',
      ],
    ];
    $draft = [
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'status' => 'failed',
      'context' => $context,
      'groups' => $groups,
      'fields' => [],
      'error' => '',
    ];
    assert($session instanceof ContentCreationSessionInterface);
    $this->storeDraft($session, $draft);

    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    $this->assertSame($draft, $messages[0]['toolCalls'][0]['result']);

    // A run that stored no field is no draft: it reaches neither the rail,
    // nor the history the model reads, nor the preview and save endpoints.
    $this->assertSame([], $session->listDrafts());
    $this->assertNull($session->getDraftContent(1));
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
    $thread = 'editorial.session_' . $session->id();

    // The model's own call, committed first and never replaced.
    $this->seedNeuronRow($session, $thread, [
      'role' => 'assistant',
      'meta' => json_encode([
        'type' => 'tool_call',
        'tools' => [
          ['name' => 'drafting_draft_content', 'callId' => 'call_1', 'inputs' => []],
        ],
      ]),
    ]);
    // The settled pair, committed immediately after. It names the same call.
    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'meta' => json_encode([
        'type' => 'tool_call_result',
        'tools' => [
          [
            'name' => 'drafting_draft_content',
            'callId' => 'call_1',
            'result' => ['draft' => 'Draft 1', 'version' => 1],
          ],
        ],
      ]),
    ]);

    assert($session instanceof ContentCreationSessionInterface);
    $this->storeDraft($session, [
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'context' => ['tone' => NULL, 'template' => NULL, 'documents' => []],
      'fields' => ['title' => [['value' => 'Test Title']]],
    ]);

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
   * Tests that a drafting call nothing settled reaches the editor as failed.
   *
   * A card that reads an open call as running spins forever. A drafting
   * call settles in the request that asked for it, so a call the transcript
   * finds open wrote no draft. A call of any other tool can still settle in
   * a later request, so it keeps its open result.
   */
  public function testGetMessagesReportsTheUnsettledCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    $thread = 'editorial.session_' . $session->id();

    $this->seedNeuronRow($session, $thread, [
      'role' => 'assistant',
      'meta' => json_encode([
        'type' => 'tool_call',
        'tools' => [
          ['name' => 'drafting_draft_content', 'callId' => 'call_2', 'inputs' => []],
          ['name' => 'drafting_get_history', 'callId' => 'call_3', 'inputs' => []],
        ],
      ]),
    ]);

    $messages = array_values(array_filter(
      $this->getMessages($session),
      fn($m) => $m['role'] !== 'event',
    ));

    $this->assertCount(1, $messages);
    $calls = $messages[0]['toolCalls'];
    $this->assertSame('drafting_draft_content', $calls[0]['function']['name']);
    $this->assertSame(
      ['status' => 'failed', 'error' => 'The draft did not finish.'],
      $calls[0]['result'],
    );
    $this->assertSame('drafting_get_history', $calls[1]['function']['name']);
    $this->assertArrayNotHasKey('result', $calls[1]);
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
    $thread = 'editorial.session_' . $session->id();

    $this->seedNeuronRow($session, $thread, [
      'role' => 'user',
      'content' => json_encode([['type' => 'text', 'content' => 'Draft a news article.', 'meta' => []]]),
    ]);
    $this->seedNeuronRow($session, $thread, [
      'role' => 'assistant',
      'content' => json_encode([['type' => 'text', 'content' => 'Here is a draft.', 'meta' => []]]),
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/reset', [
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status']);
    $this->assertSame(['status' => 'ok'], json_decode($result['body'], TRUE));

    $this->assertSame([], $session->conversationThreads(),
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
    $threads = $session->conversationThreads();
    $thread = $threads['editorial.session_' . $session->id()] ?? [];
    $texts = array_map(
      fn($row) => implode('', array_map(
        static fn (array $block): string => (string) ($block['content'] ?? ''),
        array_filter((array) ($row->getContent() ?? []), static fn ($b): bool => is_array($b) && ($b['type'] ?? '') === 'text'),
      )),
      $thread,
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
    $this->enqueueDraftFlow('Draft 2 generated with 2 fields.');
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
   * Tests that drafting_get_history returns the drafts of this session.
   *
   * The tool takes no arguments at all — unlike the deleted router-era
   * get_draft_history, it declares no session_id parameter the model could
   * supply, since the session reaches it as plugin context (confirmed by
   * reading GetDraftHistoryNeuronTool directly); there is nothing left to
   * pin against a model-supplied id, so this only proves the tool surfaces
   * the session's own drafts with their full provenance.
   */
  public function testGetDraftHistoryToolReturnsSessionDrafts(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);
    assert($session instanceof ContentCreationSessionInterface);

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
    ];
    $this->storeDraft($session, [
      'version' => 1,
      'run' => 'draft_1.abcdef',
      'context' => [
        'tone' => ['id' => '1', 'label' => 'Formal', 'prompt' => 'Use professional, institutional language.'],
        'template' => ['id' => 'news_default', 'label' => 'News default'],
        'documents' => $documents,
      ],
      'fields' => ['title' => [['value' => 'First']]],
    ]);
    $this->storeDraft($session, [
      'version' => 2,
      'run' => 'draft_2.abcdef',
      'context' => [
        'tone' => ['id' => '2', 'label' => 'Technical', 'prompt' => 'Use domain-specific terminology precisely.'],
        'template' => ['id' => 'news_default', 'label' => 'News default'],
        'documents' => [],
      ],
      'fields' => ['title' => [['value' => 'Second']]],
    ]);

    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_get_history', 'arguments' => '{}'],
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

    // The tool result was recorded on the editor's own thread.
    $threads = $session->conversationThreads();
    $thread = $threads['editorial.session_' . $session->id()] ?? [];
    $results = [];
    foreach ($thread as $row) {
      $results = array_merge($results, $row->getToolResults());
    }
    $historyResult = NULL;
    foreach ($results as $toolResult) {
      if ($toolResult['name'] === 'drafting_get_history') {
        $historyResult = $toolResult['result'];
      }
    }
    $this->assertNotNull($historyResult, 'The tool result must be recorded.');
    $payload = json_encode($historyResult);
    $this->assertStringContainsString('Draft 1', $payload);
    $this->assertStringContainsString('Draft 2', $payload);
    $this->assertStringContainsString('Formal', $payload);
    $this->assertStringContainsString('Technical', $payload);
    $this->assertStringContainsString('Climate briefing note', $payload,
      'Populated document descriptors must flow through the tool output.');
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
  protected function enqueueDraftFlow(string $confirmation = 'Draft 1 generated with 2 fields.'): void {
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => 'call_1',
          'type' => 'function',
          'function' => ['name' => 'drafting_draft_content', 'arguments' => '{}'],
        ],
      ],
    ));
    // The default oe_news template splits into two groups: main_fields and
    // field_content_paragraphs, same shape as DraftingTurnTest's kernel
    // fixture.
    MockAiProvider::enqueue(new MockResponse(
      text: '{"title": [{"value": "Test Title"}], "field_teaser": [{"value": "Test teaser."}]}',
    ));
    MockAiProvider::enqueue(new MockResponse(
      text: '{"field_content_paragraphs": [{"field_text_body": [{"value": "<p>Body</p>", "format": "full_html"}],'
        . ' "type": [{"target_id": "text_block"}]}]}',
    ));
    // The model composes this after seeing the settled tool result; the
    // backend no longer synthesizes a confirmation string itself.
    MockAiProvider::enqueue(new MockResponse(text: $confirmation));
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
    assert($session instanceof ContentCreationSessionInterface);
    $results = [];
    foreach ($session->listDrafts() as $draft) {
      $content = $session->getDraftContent($draft['version']);
      $results[] = [
        'version' => $draft['version'],
        'context' => $draft['context'],
        'fields' => $content['fields'] ?? [],
      ];
    }
    return $results;
  }

}
