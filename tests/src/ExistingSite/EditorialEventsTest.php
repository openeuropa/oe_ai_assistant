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
