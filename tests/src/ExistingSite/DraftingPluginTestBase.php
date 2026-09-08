<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Base class for drafting plugin integration tests.
 *
 * Configures the mock AI provider as the default, and provides the
 * shared helpers to create editorial sessions, authenticate, POST
 * JSON to the plugin endpoints and load the persisted transcript.
 *
 * Requires OE_AI_SKIP_PROVIDER_OVERRIDE=1 in the web container
 * environment so settings.ai.php does not override the mock
 * provider config set in setUp().
 *
 * @see .ddev/settings.ai.php
 * @see .ddev/docker-compose.phpunit.yaml
 */
abstract class DraftingPluginTestBase extends ExistingSiteBase {

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
    \Drupal::service('module_installer')->install(['oe_ai_assistant_test']);

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
   * @param int|null $uid
   *   Optional author user ID, set on user turns.
   */
  protected function seedMessage(AiEditorialSessionInterface $session, string $role, string $content, array $toolCalls = [], ?int $uid = NULL): void {
    /** @var \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface $message */
    $message = \Drupal::entityTypeManager()->getStorage('ai_conversation_message')
      ->create([
        'host_entity_type' => $session->getEntityTypeId(),
        'host_entity_id' => (int) $session->id(),
        'role' => $role,
        'content' => $content,
      ] + ($uid !== NULL ? ['uid' => $uid] : []));
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
    $storage = \Drupal::entityTypeManager()
      ->getStorage('ai_conversation_message');
    $storage->resetCache();
    return $storage->loadTranscript($session);
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
