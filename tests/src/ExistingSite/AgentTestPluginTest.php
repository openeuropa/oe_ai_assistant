<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_agent_test\Plugin\AiProvider\MockResponse;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the agent test plugin with a mock AI provider.
 *
 * Validates that the plugin can stream LLM responses as SSE events
 * using the Vercel AI SDK UI Message Stream v1 protocol.
 */
class AgentTestPluginTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Tell the agent test plugin to use the mock provider. This uses
    // Drupal state (not config) to avoid settings.php config overrides.
    \Drupal::state()->set('agent_test.provider_id', 'mock_ai');
    \Drupal::state()->set('agent_test.model_id', 'mock-model');

    MockAiProvider::reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    MockAiProvider::reset();
    parent::tearDown();
  }

  /**
   * Tests that a simple text response is streamed as SSE text-delta events.
   */
  public function testTextResponseStreamedAsSse(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    // Queue a simple text response in the mock provider.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Hello from the mock LLM provider.',
    ));

    // POST to the agent_test plugin chat action.
    $response = $this->httpPost('/api/ai/plugins/agent_test/chat', [
      'message' => 'Say hello.',
    ]);

    $this->assertEquals(200, $response['status']);

    // Parse SSE events from the response body.
    $events = $this->parseSseEvents($response['body']);

    // Should contain text-delta events with the streamed words.
    $textDeltas = array_filter($events, fn($e) => $e['type'] === 'text-delta');
    $this->assertNotEmpty($textDeltas, 'Expected text-delta SSE events.');

    // Reconstruct the full text from all text-delta events.
    $fullText = '';
    foreach ($textDeltas as $delta) {
      $fullText .= $delta['data']['textDelta'];
    }
    $this->assertEquals(
      'Hello from the mock LLM provider.',
      trim($fullText),
      'Reconstructed text should match the mock response.'
    );

    // Should end with a finish event.
    $finishEvents = array_filter($events, fn($e) => $e['type'] === 'finish');
    $this->assertNotEmpty($finishEvents, 'Expected a finish SSE event.');

    // The mock queue should be empty (all responses consumed).
    // Reset the state cache to read the latest value from the database
    // (the web server process consumed the queue, not this process).
    \Drupal::state()->resetCache();
    $this->assertTrue(MockAiProvider::isEmpty(), 'All mock responses should be consumed.');
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
   *   An array with 'status' and 'body' (raw string) keys.
   */
  protected function httpPost(string $url, array $body): array {
    /** @var \Symfony\Component\BrowserKit\AbstractBrowser $client */
    $client = $this->getSession()->getDriver()->getClient();

    $fullUrl = $this->baseUrl . $url;

    $client->request(
      'POST',
      $fullUrl,
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
   * Parses SSE events from a raw response body string.
   *
   * Each SSE frame is a "data: <json>\n\n" block. This method splits
   * the body, decodes the JSON, and returns structured event data.
   *
   * @param string $body
   *   The raw SSE response body.
   *
   * @return array
   *   Array of parsed events, each with 'type' and 'data' keys.
   */
  protected function parseSseEvents(string $body): array {
    $events = [];
    // Split on double newlines (SSE frame boundary).
    $frames = preg_split('/\n\n+/', trim($body));

    foreach ($frames as $frame) {
      $frame = trim($frame);
      if ($frame === '') {
        continue;
      }

      // Extract data lines from the frame.
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
