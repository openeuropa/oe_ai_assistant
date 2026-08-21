<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;

/**
 * Integration tests for editorial change events.
 *
 * Events are persisted conversation rows with the event role: an
 * initial-state event at session creation and a change event on every
 * tone or template change, surfaced to the client via get-messages and
 * to the model as compact history notes.
 */
class EditorialEventsTest extends DraftingPluginTestBase {

  /**
   * Tests that creating a session records an initial-state event.
   */
  public function testSessionCreationRecordsInitialStateEvent(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $session = $this->createSession($user);

    $rows = $this->loadTranscript($session);
    $this->assertNotEmpty($rows, 'A new session has a transcript row.');
    $first = reset($rows);
    $this->assertSame('event', $first->getRole(),
      'The first transcript row is the initial-state event.');
    $metadata = $first->getMetadata();
    $this->assertSame('session_start', $metadata['type']);
    $this->assertNull($metadata['from']);
    $this->assertArrayHasKey('tone', $metadata['to']);
    $this->assertArrayHasKey('template', $metadata['to']);
    $this->assertSame([], $metadata['to']['documents']);
  }

  /**
   * Tests that set-tone records change events with from and to payloads.
   */
  public function testSetToneRecordsChangeEvents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $formalId = $this->getTermIdByName('oe_ai_tone', 'Formal');
    $technicalId = $this->getTermIdByName('oe_ai_tone', 'Technical');

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $formalId,
    ]);
    $this->assertEquals(200, $result['status']);
    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $technicalId,
    ]);

    $events = $this->loadEvents($session, 'tone');
    $this->assertCount(2, $events, 'Two tone change events are recorded.');

    $firstMeta = $events[0]->getMetadata();
    $this->assertNull($firstMeta['from'],
      'The first change has no previous tone.');
    $this->assertSame($formalId, $firstMeta['to']['id']);
    $this->assertSame('Formal', $firstMeta['to']['label']);
    $this->assertSame('Tone changed to Formal',
      (string) $events[0]->get('content')->value);

    $secondMeta = $events[1]->getMetadata();
    $this->assertSame('Formal', $secondMeta['from']['label']);
    $this->assertSame('Technical', $secondMeta['to']['label']);
    $this->assertSame('Tone changed from Formal to Technical',
      (string) $events[1]->get('content')->value);
    $this->assertSame((int) $user->id(),
      (int) $events[1]->get('uid')->target_id);
  }

  /**
   * Tests that set-template records a change event.
   */
  public function testSetTemplateRecordsChangeEvent(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-template', [
      'sessionId' => $session->id(),
      'template' => 'news_default',
    ]);
    $this->assertEquals(200, $result['status'],
      'set-template should accept an enabled template. Body: '
      . substr($result['body'], 0, 300));

    $events = $this->loadEvents($session, 'template');
    $this->assertCount(1, $events);
    $meta = $events[0]->getMetadata();
    $this->assertNull($meta['from']);
    $this->assertSame('news_default', $meta['to']['id']);
    $this->assertSame('News article (default)', $meta['to']['label']);
    $this->assertSame('Template changed to News article (default)',
      (string) $events[0]->get('content')->value);
  }

  /**
   * Tests that re-selecting the current tone records no event.
   */
  public function testNoOpToneChangeRecordsNoEvent(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $formalId = $this->getTermIdByName('oe_ai_tone', 'Formal');
    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $formalId,
    ]);
    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $formalId,
    ]);

    $this->assertEquals(200, $result['status'],
      'Re-selecting the current tone still succeeds.');
    $this->assertCount(1, $this->loadEvents($session, 'tone'),
      'A no-op tone selection must not record a change event.');
  }

  /**
   * Tests that a rejected tone change records no event.
   */
  public function testInvalidToneChangeRecordsNoEvent(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => '999999',
    ]);
    $this->assertEquals(400, $result['status']);
    $this->assertCount(0, $this->loadEvents($session, 'tone'),
      'A rejected change must not leave an event row.');
  }

  /**
   * Tests that recordEvent persists a session-scoped event row.
   */
  public function testRecordEventPersistsRow(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $session = $this->createSession($user);

    $recorder = \Drupal::service(MessageRecorderInterface::class);
    $metadata = [
      'type' => 'tone',
      'from' => ['id' => '1', 'label' => 'Conversational'],
      'to' => ['id' => '2', 'label' => 'Formal'],
    ];
    $recorder->recordEvent(
      $session, 'Tone changed from Conversational to Formal',
      $metadata, (int) $user->id(),
    );

    $rows = $this->loadTranscript($session);
    $events = array_values(array_filter(
      $rows, fn($m) => $m->getRole() === 'event' && ($m->getMetadata()['type'] ?? '') === 'tone',
    ));
    $this->assertCount(1, $events, 'One event row is persisted.');
    $event = $events[0];
    $this->assertSame(
      'Tone changed from Conversational to Formal',
      (string) $event->get('content')->value,
    );
    $this->assertSame($metadata, $event->getMetadata());
    $this->assertSame(
      (int) $user->id(),
      (int) $event->get('uid')->target_id,
      'The acting user is recorded on the event row.',
    );
  }

  /**
   * Tests that event rows reach the model as compact history notes.
   */
  public function testEventsAppearInModelHistoryAsNotes(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);

    MockAiProvider::enqueue(new MockResponse(text: 'Understood.'));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Hello.',
      'sessionId' => $session->id(),
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log);
    $texts = array_column($log[0]['messages'], 'text');

    $this->assertNotEmpty(array_filter(
      $texts,
      fn($t) => str_contains($t, '[Editorial change] Tone changed to Formal'),
    ), 'The tone change note is in the model history.');
    $this->assertNotEmpty(array_filter(
      $texts,
      fn($t) => str_contains($t, '[Editorial change] Session started'),
    ), 'The initial-state note is in the model history.');
  }

  /**
   * Loads the persisted event rows of a given type, oldest first.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $type
   *   The event metadata type to filter by.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface[]
   *   The matching event rows.
   */
  protected function loadEvents(AiEditorialSessionInterface $session, string $type): array {
    return array_values(array_filter(
      $this->loadTranscript($session),
      fn($m) => $m->getRole() === 'event'
        && ($m->getMetadata()['type'] ?? '') === $type,
    ));
  }

  /**
   * Tests that get-messages returns event rows in transcript order.
   */
  public function testGetMessagesReturnsEvents(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $session = $this->createSession($user);

    $this->httpPost('/api/ai/plugins/drafting/set-tone', [
      'sessionId' => $session->id(),
      'toneId' => $this->getTermIdByName('oe_ai_tone', 'Formal'),
    ]);

    $result = $this->httpPost('/api/ai/plugins/drafting/get-messages', [
      'sessionId' => $session->id(),
    ]);
    $this->assertEquals(200, $result['status']);
    $messages = json_decode($result['body'], TRUE)['messages'] ?? [];

    $this->assertCount(2, $messages,
      'The initial-state event and the tone change are returned.');

    $this->assertSame('event', $messages[0]['role']);
    $this->assertSame('session_start', $messages[0]['type']);
    $this->assertStringContainsString('Session started', $messages[0]['summary']);
    $this->assertNotEmpty($messages[0]['at']);

    $this->assertSame('event', $messages[1]['role']);
    $this->assertSame('tone', $messages[1]['type']);
    $this->assertSame('Tone changed to Formal', $messages[1]['summary']);
    $this->assertArrayNotHasKey('content', $messages[1],
      'Event items use summary, not content.');
  }

}
