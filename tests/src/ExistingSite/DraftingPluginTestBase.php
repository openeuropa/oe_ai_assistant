<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\SessionConversation;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
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
   * The CSRF token of the logged-in browser session, fetched on demand.
   */
  protected ?string $csrfToken = NULL;

  /**
   * The agent whose conversation the editor has.
   */
  private const AGENT_ID = 'drafting';

  /**
   * The message ids seeded so far, which keeps each one unique.
   *
   * @var string[]
   */
  private array $seeded = [];

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
        // The group drafters answer with structured output, which ai_neuron
        // resolves as its own operation type.
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
    // Remove the conversations persisted against the test sessions, the
    // drafter threads included.
    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    foreach ($this->sessions as $session) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('oe_ai_session', (int) $session->id())
        ->execute();
      $storage->delete($storage->loadMultiple($ids));
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
   * Seeds a conversation message on the session's drafting thread.
   *
   * Writes what a real run writes: a turn carrying tool calls is one assistant
   * row with the calls and one tool result row with their results, which is
   * how Neuron commits the pair.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $role
   *   The message role.
   * @param string $content
   *   The message text.
   * @param array $toolCalls
   *   Optional tool calls, in the shape the transcript exposes them:
   *   {function: {name, arguments}, result}.
   * @param int|null $uid
   *   Optional author user ID, set on user turns.
   */
  protected function seedMessage(AiEditorialSessionInterface $session, string $role, string $content, array $toolCalls = [], ?int $uid = NULL): void {
    $tools = [];
    foreach ($toolCalls as $index => $call) {
      $arguments = $call['function']['arguments'] ?? '{}';
      $tools[] = [
        'callId' => sprintf('call_seed_%d_%d', count($this->seeded), $index),
        'name' => $call['function']['name'] ?? '',
        'deferred' => FALSE,
        'inputs' => (array) json_decode((string) $arguments, TRUE),
        'result' => json_encode($call['result'] ?? []),
      ];
    }

    $meta = $tools === [] ? [] : ['type' => 'tool_call', 'tools' => $tools];
    $this->seedRow($session, $role, $content, $meta, $uid);

    if ($tools !== []) {
      $this->seedRow($session, 'user', '', ['type' => 'tool_call_result', 'tools' => $tools], NULL);
    }
  }

  /**
   * Writes one row of the session's drafting thread.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $role
   *   The Neuron message role.
   * @param string $content
   *   The message text, which becomes its one text block.
   * @param array $meta
   *   Everything the message serializes other than its role and content.
   * @param int|null $uid
   *   The author, or NULL for a row nobody wrote.
   */
  private function seedRow(AiEditorialSessionInterface $session, string $role, string $content, array $meta, ?int $uid): void {
    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    $threadId = $this->threadOf($session);
    // A row the editor wrote opens a turn; a tool result joins the turn that
    // called it, which is the distinction the store itself makes.
    $opensTurn = $role === 'user' && ($meta['type'] ?? '') !== 'tool_call_result';

    $this->seeded[] = $id = sprintf('msg_seed_%s_%d', $session->id(), count($this->seeded));
    $storage->create([
      'bundle' => 'editorial_session',
      'oe_ai_session' => (int) $session->id(),
      'thread_id' => $threadId,
      'message_id' => $id,
      'role' => $role,
      'content' => json_encode([['type' => 'text', 'content' => $content, 'meta' => []]]),
      'meta' => $meta === [] ? NULL : json_encode($meta + ['__id' => $id]),
      'turn' => $storage->nextTurn($threadId, $opensTurn),
      'complete' => TRUE,
      'agent_id' => self::AGENT_ID,
      'uid' => $uid,
    ])->save();
  }

  /**
   * Seeds a stored draft on the transcript, as the drafting flow records it.
   *
   * An assistant turn carrying a draft_group tool call whose result holds
   * the draft, shaped {version, major, minor, context, fields}. Seeding
   * bypasses the chat flow, which is tested on its own.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param int $version
   *   The draft version.
   * @param array $fields
   *   The drafted field values, keyed by field machine name.
   * @param array $context
   *   The context snapshot: tone, template and documents, each optional.
   * @param int|null $major
   *   The group number, or NULL to open a new group numbered as the version.
   * @param int $minor
   *   The position within the group.
   */
  protected function seedDraft(AiEditorialSessionInterface $session, int $version, array $fields, array $context = [], ?int $major = NULL, int $minor = 0): void {
    // The request that asked for the draft, so the seeded turn reads back as a
    // turn: Neuron refuses a conversation that does not start with one.
    $this->seedMessage($session, 'user', 'Draft it.');
    $this->seedMessage($session, 'assistant', '', [
      [
        'type' => 'function',
        'function' => ['name' => 'draft_group', 'arguments' => '{"group":"main_fields"}'],
        'result' => [
          'group' => 'main_fields',
          'draft' => [
            'version' => $version,
            'major' => $major ?? $version,
            'minor' => $minor,
            'context' => $context + ['tone' => NULL, 'template' => NULL, 'documents' => []],
            'fields' => $fields,
          ],
        ],
      ],
    ]);
    // The answer that closes the turn. A turn the model never answered leaves
    // the conversation expecting one, which Neuron refuses to read.
    $this->seedMessage($session, 'assistant', sprintf('Draft %d.0 is ready.', $major ?? $version));
  }

  /**
   * Loads the stored conversation of a session, oldest row first.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The stored message rows.
   */
  protected function loadTranscript(AiEditorialSessionInterface $session): array {
    return \Drupal::service(SessionConversation::class)->rows($this->threadOf($session));
  }

  /**
   * Loads the conversation as the editor reads it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   *
   * @return array
   *   The turns, each {role, content, at} and toolCalls where there are any.
   */
  protected function loadTurns(AiEditorialSessionInterface $session): array {
    return $this->getMessages($session);
  }

  /**
   * Saves a draft the way the app does: the model asks, the editor approves.
   *
   * The save is a gated tool call, so the round trip is two requests: the turn
   * that asks, and the decision that lets it run.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session owning the draft.
   * @param int $version
   *   The draft version to save.
   * @param string $callId
   *   The id the call is made under, which the decision names.
   *
   * @return array
   *   What the tool answered: the version, the name and the node it wrote, or
   *   an error. Empty when the call never ran.
   */
  protected function approveSave(AiEditorialSessionInterface $session, int $version, string $callId = 'call_save'): array {
    MockAiProvider::enqueue(new MockResponse(
      toolCalls: [
        [
          'id' => $callId,
          'type' => 'function',
          'function' => [
            'name' => 'save_draft',
            'arguments' => json_encode(['version' => $version]),
          ],
        ],
      ],
    ));
    MockAiProvider::enqueue(new MockResponse(text: 'Done.'));

    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => sprintf('Save draft %d.', $version),
      'sessionId' => $session->id(),
    ]);
    $result = $this->httpPost('/api/ai/plugins/drafting/submit-approval', [
      'sessionId' => $session->id(),
      'callId' => $callId,
      'decision' => 'approve',
    ]);

    foreach ($this->parseSseEvents($result['body']) as $event) {
      if ($event['type'] === 'tool-output-available' && ($event['toolCallId'] ?? '') === $callId) {
        return (array) json_decode((string) $event['output'], TRUE);
      }
    }

    return [];
  }

  /**
   * The RFC 3339 time a stored row was written, as the transcript exposes it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $row
   *   The stored message.
   *
   * @return string
   *   The timestamp.
   */
  protected function createdOf(ContentEntityInterface $row): string {
    return \DateTimeImmutable::createFromFormat('U', (string) $row->get('created')->value)->format('c');
  }

  /**
   * The thread the session's drafting conversation is held under.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return string
   *   The thread id.
   */
  protected function threadOf(AiEditorialSessionInterface $session): string {
    return ThreadAddress::thread(self::AGENT_ID, (string) $session->id());
  }

  /**
   * The rows every drafter run of a session wrote, oldest first.
   *
   * A drafter holds a thread of its own, so these are not in the editor's
   * conversation. The session reference is what relates them to it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The rows.
   */
  protected function loadDrafterRows(AiEditorialSessionInterface $session): array {
    $storage = \Drupal::entityTypeManager()->getStorage('neuron_message');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('oe_ai_session', (int) $session->id())
      ->condition('thread_id', $this->threadOf($session), '<>')
      ->sort('id')
      ->execute();

    return $ids === [] ? [] : $storage->loadMultiple($ids);
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
   * The result the named tool answered with, as the transcript holds it.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session hosting the conversation.
   * @param string $name
   *   The tool name.
   *
   * @return array|null
   *   The decoded result, or NULL when the tool was not called.
   */
  protected function resultOfToolCall(AiEditorialSessionInterface $session, string $name): ?array {
    foreach ($this->loadTurns($session) as $turn) {
      foreach ($turn['toolCalls'] ?? [] as $call) {
        if (($call['function']['name'] ?? '') === $name && isset($call['result'])) {
          return $call['result'];
        }
      }
    }

    return NULL;
  }

}
