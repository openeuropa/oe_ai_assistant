<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for editorial change events.
 *
 * Events are persisted conversation rows with the event role: an
 * initial-state event at session creation and a change event on every
 * tone or template change, surfaced to the client via get-messages and
 * to the model as compact history notes.
 */
class EditorialEventsTest extends ExistingSiteBase {

  /**
   * Sessions created by the test, cleared of messages on teardown.
   *
   * @var \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface[]
   */
  protected array $sessions = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    foreach ($this->sessions as $session) {
      $storage->deleteForHost($session);
    }
    parent::tearDown();
  }

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
   * Loads the persisted top-level transcript for a session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface[]
   *   The transcript entities.
   */
  protected function loadTranscript(AiEditorialSessionInterface $session): array {
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    $storage->resetCache();
    return $storage->loadTranscript($session);
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
    $client->request(
      'POST',
      $this->baseUrl . $url,
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
   * Returns the taxonomy term ID for a fixture term.
   */
  protected function getTermIdByName(string $vid, string $name): string {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = reset($terms);
    if (!$term) {
      $this->fail(sprintf('Term "%s" was not found in "%s".', $name, $vid));
    }
    return (string) $term->id();
  }

}
