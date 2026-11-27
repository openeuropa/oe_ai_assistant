<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use Drupal\oe_ai_assistant\Utility\DraftVersion;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\user\UserInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowStatus;
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
   * The IDs of existing entities before the test, keyed by entity type.
   *
   * @var array
   */
  protected array $existingEntityIds = [];

  /**
   * The CSRF token of the logged-in browser session, fetched on demand.
   */
  protected ?string $csrfToken = NULL;

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
        // The draft_group workflow drafts each group through a
        // structured response, same as DraftingTurnTest's kernel setup.
        'chat_with_structured_response' => [
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
    try {
      // Remove any conversation messages persisted against the test
      // sessions.
      foreach ($this->sessions as $session) {
        $session->deleteConversation();
        $engine = new WorkflowEngine(\Drupal::service(PersistenceInterface::class));
        // The engine throws on a run that is still leased, and a test
        // aborted mid-turn leaves one behind. Thrown from tearDown, that
        // would replace the failure the test was reporting, so only drop
        // a run that has suspended.
        if ($engine->inspect($session->editorialThread())?->status === WorkflowStatus::Suspended) {
          $engine->abandon($session->editorialThread());
        }
      }
    }
    finally {
      // Reset these whatever happened above. The mock queue and the
      // provider configuration outlive the test, and the next one reads
      // both.
      MockAiProvider::reset();
      $this->restoreConfiguration();
      parent::tearDown();
    }
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
   * Records a draft of a session, as DraftContentNode::storeDraft() does.
   *
   * A reader finds one draft by its version. The row therefore carries the
   * session and the version.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the draft belongs to.
   * @param array $draft
   *   The record of the run, as the node writes it.
   */
  protected function storeDraft(ContentCreationSessionInterface $session, array $draft): void {
    // DraftContentNode always stores a normalized "major.minor" version,
    // and draftResult() normalizes the version it looks up. A fixture
    // stored under a plain "1" would never be found again.
    $draft['version'] = DraftVersion::normalize((string) $draft['version']);
    $message = (new EditorialAssistantMessage(json_encode($draft)))
      ->setDraftVersion($draft['version']);

    \Drupal::service(EditorialMessageStore::class)
      ->forSession($session)
      ->append($session->draftsThread(), $message);
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
    $this->csrfToken = NULL;
  }

  /**
   * Returns the CSRF token of the logged-in browser session.
   */
  protected function getCsrfToken(): string {
    if ($this->csrfToken === NULL) {
      /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
      $client = $this->getSession()->getDriver()->getClient();
      $client->request('GET', $this->baseUrl . '/session/token');
      $this->csrfToken = (string) $client->getResponse()->getContent();
    }
    return $this->csrfToken;
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
      [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $this->getCsrfToken(),
      ],
      json_encode($body),
    );
    $response = $client->getResponse();
    return [
      'status' => $response->getStatusCode(),
      'body' => $response->getContent(),
    ];
  }

  /**
   * Sends a raw-body POST request, as the document upload does.
   *
   * @param string $url
   *   The URL to request, including any query string.
   * @param string $body
   *   The raw request body.
   *
   * @return array
   *   An array with 'status' (int) and 'body' (raw string) keys.
   */
  protected function httpPostRaw(string $url, string $body): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();
    $client->request(
      'POST',
      $this->baseUrl . $url,
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_X_CSRF_TOKEN' => $this->getCsrfToken(),
      ],
      $body,
    );
    $response = $client->getResponse();
    return [
      'status' => $response->getStatusCode(),
      'body' => $response->getContent(),
    ];
  }

  /**
   * Sends a GET request with query parameters using the BrowserKit client.
   *
   * @param string $url
   *   The URL to request.
   * @param array $query
   *   The query parameters to append.
   *
   * @return array
   *   An array with 'status' (int) and 'body' (raw string) keys.
   */
  protected function httpGet(string $url, array $query = []): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();
    $client->request(
      'GET',
      $this->baseUrl . $url . ($query === [] ? '' : '?' . http_build_query($query)),
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

  /**
   * Seeds a completed draft version into the session's drafts thread.
   *
   * A draft is a row of the session's drafts thread. The editor's own
   * conversation holds none.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version number.
   * @param array $fields
   *   The drafted field values, keyed by field machine name.
   * @param string|null $templateId
   *   The template id to snapshot in the result context, or NULL for none.
   */
  protected function seedDraft(AiEditorialSessionInterface $session, int $version, array $fields, ?string $templateId = NULL): void {
    assert($session instanceof ContentCreationSessionInterface);
    $this->storeDraft($session, [
      'version' => $version,
      'run' => sprintf('draft_%d.abcdef', $version),
      'context' => [
        'tone' => NULL,
        'template' => $templateId !== NULL ? ['id' => $templateId, 'label' => $templateId] : NULL,
        'documents' => [],
      ],
      'fields' => $fields,
    ]);
  }

  /**
   * Records existing entity IDs so test-created entities can be cleaned up.
   */
  protected function trackEntityType(string $entityType): void {
    $this->existingEntityIds[$entityType] = \Drupal::entityTypeManager()
      ->getStorage($entityType)
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Deletes entities created during the test.
   */
  protected function deleteTestEntities(): void {
    foreach ($this->existingEntityIds as $entityType => $previousIds) {
      $storage = \Drupal::entityTypeManager()->getStorage($entityType);
      $currentIds = $storage->getQuery()->accessCheck(FALSE)->execute();
      $newIds = array_diff($currentIds, $previousIds);
      if ($newIds) {
        $storage->delete($storage->loadMultiple($newIds));
      }
    }
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

}
