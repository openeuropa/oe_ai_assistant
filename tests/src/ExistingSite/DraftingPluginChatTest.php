<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\ExistingSite;

use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\oe_ai_assistant\Service\AiEditorialContextInterface;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockAiProvider;
use Drupal\oe_ai_assistant_test\Plugin\AiProvider\MockResponse;
use Drupal\Tests\oe_ai_assistant\Traits\ExistingSiteConfigBackupTrait;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\UserInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Integration tests for the DraftingPlugin chat action.
 *
 * Sends real HTTP POST requests to /api/ai/plugins/drafting/chat
 * with a mock AI provider and verifies the SSE response stream.
 *
 * Requires OE_AI_SKIP_PROVIDER_OVERRIDE=1 in the web container
 * environment so settings.ai.php does not override the mock
 * provider config set in setUp().
 *
 * @see .ddev/settings.ai.php
 * @see .ddev/docker-compose.phpunit.yaml
 */
class DraftingPluginChatTest extends ExistingSiteBase {

  use ExistingSiteConfigBackupTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure the shared test module is enabled (provides MockAiProvider).
    \Drupal::service('module_installer')
      ->install(['oe_ai_assistant_test']);
    $this->ensureEditorialTaxonomyConfig();
    $this->ensureEditorialTerms();

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
    MockAiProvider::reset();
    $this->restoreConfiguration();
    parent::tearDown();
  }

  /**
   * Tests that a text response is streamed as SSE events.
   *
   * Verifies the full SSE lifecycle: start, start-step, text-delta
   * events with the LLM response text, finish-step, finish, [DONE].
   */
  public function testTextResponseStreamedAsSse(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $context = $this->draftingContext();
    $selection = $this->getEditorialSelection();

    MockAiProvider::enqueue(new MockResponse(
      text: 'Hello from the drafting assistant.',
    ));

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Hi there.',
      ...$context,
      ...$this->selectionRequestValues($selection),
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200 response. Body: ' . substr($result['body'], 0, 500));

    $events = $this->parseSseEvents($result['body']);

    // Verify SSE lifecycle events are present.
    $types = array_column($events, 'type');
    $this->assertContains('start', $types, 'SSE must include a start event.');
    $this->assertContains('start-step', $types, 'SSE must include a start-step event.');
    $this->assertContains('finish-step', $types, 'SSE must include a finish-step event.');
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

    // Verify mock provider was actually used (call log has an entry).
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log, 'Mock provider should have been called once.');

    // Verify the system prompt includes the agent config entity's prompt.
    $this->assertStringContainsString(
      'content drafting assistant',
      $log[0]['system_prompt'],
      'System prompt should come from the oe_drafting_router config entity.',
    );
    $this->assertPromptContainsSelection($log[0]['system_prompt'], $selection);
    $this->assertStringContainsString(
      'Available field groups:',
      $log[0]['system_prompt'],
      'System prompt should keep schema group context.',
    );
  }

  /**
   * Tests that conversation history persists across turns.
   *
   * Both requests share the same threadId so history accumulates
   * on the server. Turn 2's LLM call should include the user
   * message from turn 1.
   */
  public function testConversationHistoryPersists(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $threadId = bin2hex(random_bytes(16));
    $context = $this->draftingContext($threadId);
    $selection = $this->getEditorialSelection();

    // Turn 1.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Got it, you want to write about climate.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'I want to write about climate change.',
      ...$context,
      ...$this->selectionRequestValues($selection),
    ]);

    // Turn 2 with the same threadId.
    MockAiProvider::enqueue(new MockResponse(
      text: 'Sure, focusing on EU policy.',
    ));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Focus on EU policy please.',
      ...$context,
      ...$this->selectionRequestValues($selection),
    ]);

    // Check that turn 2's LLM call includes both messages.
    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two LLM calls should have been made.');

    $turn2Messages = $log[1]['messages'];
    $turn2Texts = array_column($turn2Messages, 'text');

    $this->assertContains(
      'I want to write about climate change.',
      $turn2Texts,
      'Turn 2 should include turn 1 user message in history.',
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
   * When the LLM calls draft_content (the signal), the orchestrator
   * splits the schema into groups and dispatches one sub-agent per
   * group. The test verifies data-plan and data-drafted-fields
   * events are emitted.
   */
  public function testDraftContentTriggersOrchestration(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $context = $this->draftingContext();
    $selection = $this->getEditorialSelection();

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
      ...$context,
      ...$this->selectionRequestValues($selection),
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

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(4, $log, 'Router and three sub-agents should run.');
    $this->assertPromptContainsSelection($log[0]['system_prompt'], $selection);
    $this->assertSubAgentTaskContainsSelection($log[1]['messages'], $selection);
  }

  /**
   * Tests that changed request selections are used by the next chat request.
   */
  public function testChangedSelectionUsedByNextChatRequest(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $threadId = bin2hex(random_bytes(16));
    $context = $this->draftingContext($threadId);
    $initialSelection = $this->getEditorialSelection('General public', 'Formal');

    MockAiProvider::enqueue(new MockResponse(text: 'First response.'));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Draft once.',
      ...$context,
      ...$this->selectionRequestValues($initialSelection),
    ]);

    $updatedSelection = $this->getEditorialSelection(
      'Policy makers',
      'Technical',
    );

    MockAiProvider::enqueue(new MockResponse(text: 'Second response.'));
    $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Draft again.',
      ...$context,
      ...$this->selectionRequestValues($updatedSelection),
    ]);

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(2, $log, 'Two LLM calls should have been made.');
    $this->assertPromptContainsSelection($log[1]['system_prompt'], $updatedSelection);
    $this->assertStringNotContainsString(
      'Target audience: General public',
      $log[1]['system_prompt'],
      'The second request should not use stale audience guidance.',
    );
    $this->assertStringNotContainsString(
      'Tone: Formal',
      $log[1]['system_prompt'],
      'The second request should not use stale tone guidance.',
    );
  }

  /**
   * Tests that missing request selections use the neutral prompt.
   */
  public function testMissingSelectionUsesNeutralPrompt(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $requestContext = $this->draftingContext(bin2hex(random_bytes(16)));

    MockAiProvider::enqueue(new MockResponse(text: 'Neutral response.'));
    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Use neutral guidance.',
      ...$requestContext,
    ]);

    $this->assertEquals(200, $result['status'],
      'Expected 200. Body: ' . substr($result['body'], 0, 500));

    \Drupal::state()->resetCache();
    $log = MockAiProvider::getCallLog();
    $this->assertCount(1, $log, 'One LLM call should have been made.');
    $this->assertStringContainsString(
      'Before drafting, ask the user to select a target audience and writing tone.',
      $log[0]['system_prompt'],
    );
  }

  /**
   * Tests that incomplete selections are blocked before provider calls.
   */
  public function testIncompleteSelectionBlocksBeforeProviderCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $selection = $this->getEditorialSelection();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate with one selection.',
      ...$this->draftingContext(bin2hex(random_bytes(16))),
      'audienceId' => $selection['audience']['id'],
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertStringContainsString(
      'missing_editorial_selection',
      $result['body'],
    );

    \Drupal::state()->resetCache();
    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Tests that an invalid audience ID is rejected before provider calls.
   */
  public function testInvalidAudienceIdBlocksBeforeProviderCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $selection = $this->getEditorialSelection();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate with invalid audience.',
      ...$this->draftingContext(bin2hex(random_bytes(16))),
      'audienceId' => $selection['tone']['id'],
      'toneId' => $selection['tone']['id'],
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertStringContainsString(
      'invalid_editorial_selection',
      $result['body'],
    );

    \Drupal::state()->resetCache();
    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Tests that an invalid tone ID is rejected before provider calls.
   */
  public function testInvalidToneIdBlocksBeforeProviderCall(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);
    $selection = $this->getEditorialSelection();

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => 'Generate with invalid tone.',
      ...$this->draftingContext(bin2hex(random_bytes(16))),
      'audienceId' => $selection['audience']['id'],
      'toneId' => $selection['audience']['id'],
    ]);

    $this->assertEquals(400, $result['status']);
    $this->assertStringContainsString(
      'invalid_editorial_selection',
      $result['body'],
    );

    \Drupal::state()->resetCache();
    $this->assertSame([], MockAiProvider::getCallLog());
  }

  /**
   * Tests that an empty message returns a 400 error.
   */
  public function testEmptyMessageReturns400(): void {
    $user = $this->createUser(['use oe ai assistant']);
    $this->loginUser($user);

    $result = $this->httpPost('/api/ai/plugins/drafting/chat', [
      'message' => '',
    ]);

    $this->assertEquals(400, $result['status']);
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
   * Builds a backend drafting context for chat requests.
   *
   * @return array{threadId?: string, entityTypeId: string, bundle: string}
   *   The drafting context.
   */
  protected function draftingContext(?string $threadId = NULL): array {
    $context = [
      'entityTypeId' => 'node',
      'bundle' => 'oe_news',
    ];
    if ($threadId !== NULL) {
      $context['threadId'] = $threadId;
    }

    return $context;
  }

  /**
   * Ensures the editorial taxonomy terms used by this test exist.
   */
  protected function ensureEditorialTerms(): void {
    $this->ensureEditorialTerm(
      'oe_ai_target_audience',
      'General public',
      'Content should be easy to understand for non-experts.',
      'Write in clear, accessible language. Avoid jargon and acronyms.',
    );
    $this->ensureEditorialTerm(
      'oe_ai_target_audience',
      'Policy makers',
      'Content tailored for policy experts.',
      'Use precise language. Reference regulatory frameworks.',
    );
    $this->ensureEditorialTerm(
      'oe_ai_tone',
      'Formal',
      'A professional and neutral tone.',
      'Use professional, institutional language.',
    );
    $this->ensureEditorialTerm(
      'oe_ai_tone',
      'Technical',
      'A detailed and structured tone.',
      'Use domain-specific terminology precisely.',
    );
  }

  /**
   * Ensures the editorial vocabularies and prompt field exist.
   */
  protected function ensureEditorialTaxonomyConfig(): void {
    $this->ensureVocabulary(
      'oe_ai_target_audience',
      'AI target audience',
      'Editorial target audience options for AI-assisted drafting.',
    );
    $this->ensureVocabulary(
      'oe_ai_tone',
      'AI tone',
      'Editorial tone options for AI-assisted drafting.',
    );

    if (!FieldStorageConfig::loadByName('taxonomy_term', 'field_oe_ai_prompt')) {
      FieldStorageConfig::create([
        'field_name' => 'field_oe_ai_prompt',
        'entity_type' => 'taxonomy_term',
        'type' => 'string_long',
      ])->save();
    }

    $this->ensurePromptField('oe_ai_target_audience');
    $this->ensurePromptField('oe_ai_tone');
  }

  /**
   * Ensures one vocabulary exists.
   */
  protected function ensureVocabulary(
    string $vid,
    string $name,
    string $description,
  ): void {
    if (Vocabulary::load($vid)) {
      return;
    }

    Vocabulary::create([
      'vid' => $vid,
      'name' => $name,
      'description' => $description,
    ])->save();
  }

  /**
   * Ensures the AI prompt field exists on a vocabulary.
   */
  protected function ensurePromptField(string $bundle): void {
    if (FieldConfig::loadByName('taxonomy_term', $bundle, 'field_oe_ai_prompt')) {
      return;
    }

    FieldConfig::create([
      'field_name' => 'field_oe_ai_prompt',
      'entity_type' => 'taxonomy_term',
      'bundle' => $bundle,
      'label' => 'AI prompt',
      'description' => 'LLM-facing guidance injected into the system prompt.',
    ])->save();
  }

  /**
   * Ensures one editorial taxonomy term exists.
   */
  protected function ensureEditorialTerm(
    string $vid,
    string $name,
    string $description,
    string $prompt,
  ): void {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vid,
        'name' => $name,
      ]);
    if ($terms !== []) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = reset($terms);
      $term->setDescription($description);
      $term->set('field_oe_ai_prompt', $prompt);
      $term->save();
      return;
    }

    $term = Term::create([
      'vid' => $vid,
      'name' => $name,
      'description' => [
        'value' => $description,
        'format' => 'plain_text',
      ],
      'field_oe_ai_prompt' => $prompt,
    ]);
    $term->save();
  }

  /**
   * Gets selected audience and tone options for chat request parameters.
   *
   * @param string $audienceName
   *   The audience label.
   * @param string $toneName
   *   The tone label.
   *
   * @return array{audience: array{id: string, name: string, description: string, oe_ai_prompt: string}, tone: array{id: string, name: string, description: string, oe_ai_prompt: string}}
   *   The selected audience and tone options.
   */
  protected function getEditorialSelection(
    string $audienceName = 'General public',
    string $toneName = 'Formal',
  ): array {
    /** @var \Drupal\oe_ai_assistant\Service\AiEditorialContextInterface $editorialContext */
    $editorialContext = \Drupal::service(AiEditorialContextInterface::class);
    $audience = $this->findEditorialOption(
      $editorialContext->getAvailableAudiences(),
      $audienceName,
    );
    $tone = $this->findEditorialOption(
      $editorialContext->getAvailableTones(),
      $toneName,
    );

    return [
      'audience' => $audience,
      'tone' => $tone,
    ];
  }

  /**
   * Builds chat request values from selected editorial options.
   *
   * @param array{audience: array{id: string}, tone: array{id: string}} $selection
   *   The selected audience and tone options.
   *
   * @return array{audienceId: string, toneId: string}
   *   Chat request parameter values.
   */
  protected function selectionRequestValues(array $selection): array {
    return [
      'audienceId' => $selection['audience']['id'],
      'toneId' => $selection['tone']['id'],
    ];
  }

  /**
   * Finds an editorial option by label.
   *
   * @param array<int, array{id: string, name: string, description: string, oe_ai_prompt: string}> $options
   *   The available options.
   * @param string $name
   *   The option label.
   *
   * @return array{id: string, name: string, description: string, oe_ai_prompt: string}
   *   The matching option.
   */
  protected function findEditorialOption(array $options, string $name): array {
    foreach ($options as $option) {
      if ($option['name'] === $name) {
        return $option;
      }
    }

    throw new \RuntimeException(sprintf(
      'Editorial option "%s" was not found.',
      $name,
    ));
  }

  /**
   * Asserts a prompt contains selected audience and tone guidance.
   *
   * @param array{audience: array{name: string, oe_ai_prompt: string}, tone: array{name: string, oe_ai_prompt: string}} $selection
   *   The expected selection.
   */
  protected function assertPromptContainsSelection(
    string $prompt,
    array $selection,
  ): void {
    $this->assertStringContainsString(
      'Target audience: ' . $selection['audience']['name'],
      $prompt,
    );
    $this->assertStringContainsString(
      $selection['audience']['oe_ai_prompt'],
      $prompt,
    );
    $this->assertStringContainsString(
      'Tone: ' . $selection['tone']['name'],
      $prompt,
    );
    $this->assertStringContainsString(
      $selection['tone']['oe_ai_prompt'],
      $prompt,
    );
  }

  /**
   * Asserts the sub-agent task prompt contains selected guidance.
   *
   * @param array<int, array{role: string, text: string}> $messages
   *   The logged sub-agent messages.
   * @param array{audience: array{name: string, oe_ai_prompt: string}, tone: array{name: string, oe_ai_prompt: string}} $selection
   *   The expected selection.
   */
  protected function assertSubAgentTaskContainsSelection(
    array $messages,
    array $selection,
  ): void {
    $taskPrompt = implode("\n", array_column($messages, 'text'));
    $this->assertStringContainsString('Editorial guidance:', $taskPrompt);
    $this->assertPromptContainsSelection($taskPrompt, $selection);
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
