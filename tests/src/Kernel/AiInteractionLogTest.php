<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\ReplayedChatMessageIterator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiInteractionLog;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Kernel tests for AI interaction logs.
 */
#[Group('oe_ai_assistant')]
class AiInteractionLogTest extends KernelTestBase {

  /**
   * Mock provider ID used by the logging fixtures.
   */
  protected const MOCK_PROVIDER_ID = 'mock_ai';

  /**
   * Mock model ID used by the logging fixtures.
   */
  protected const MOCK_MODEL_ID = 'mock-model';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_observability',
    'ai_agents',
    'content_moderation',
    'datetime',
    'extended_logger',
    'field',
    'filter',
    'oe_ai_assistant',
    'serialization',
    'system',
    'user',
    'workflows',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('ai_interaction_log');
  }

  /**
   * Tests that observability-shaped data can be persisted.
   */
  public function testCrudStoresStructuredAndRawPayload(): void {
    $payload = [
      'provider' => self::MOCK_PROVIDER_ID,
      'model' => self::MOCK_MODEL_ID,
      'event_name' => 'ai.post_generate_response',
      'operation_type' => 'chat',
      'channel' => 'ai_observability',
      'provider_request_id' => 'req_123',
      'provider_parent_request_id' => 'parent_req_123',
      'usage' => [
        'input_tokens' => 100,
        'output_tokens' => 50,
        'total_tokens' => 150,
        'cached_tokens' => 10,
      ],
      'request_uri' => 'https://example.com/node/add/news',
      'referer' => 'https://example.com/admin/content',
      'base_url' => 'https://example.com',
      'user_id' => 7,
      'ip' => '0.0.0.0',
      'severity' => 'info',
      'timestamp' => 1_718_000_000,
      'tags' => ['drafting', 'content_creation'],
      'guardrails' => ['status' => 'passed'],
      'configuration' => ['temperature' => 0.2],
      'metadata' => ['route' => 'oe_ai_assistant.plugin.dispatch'],
      'input' => [['role' => 'user', 'content' => 'Create a news draft.']],
      'output' => [['role' => 'assistant', 'content' => 'Draft content.']],
    ];

    /** @var \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface $log */
    $log = $this->container->get('entity_type.manager')
      ->getStorage('ai_interaction_log')
      ->create([
        'provider' => $payload['provider'],
        'model' => $payload['model'],
        'event_name' => $payload['event_name'],
        'operation_type' => $payload['operation_type'],
        'channel' => $payload['channel'],
        'provider_request_id' => $payload['provider_request_id'],
        'provider_parent_request_id' => $payload['provider_parent_request_id'],
        'input_tokens' => $payload['usage']['input_tokens'],
        'output_tokens' => $payload['usage']['output_tokens'],
        'total_tokens' => $payload['usage']['total_tokens'],
        'cached_tokens' => $payload['usage']['cached_tokens'],
        'request_uri' => $payload['request_uri'],
        'referer' => $payload['referer'],
        'base_url' => $payload['base_url'],
        'user_id' => $payload['user_id'],
        'ip' => $payload['ip'],
        'severity' => $payload['severity'],
        'event_timestamp' => $payload['timestamp'],
        'tags' => json_encode($payload['tags'], JSON_THROW_ON_ERROR),
        'guardrails' => json_encode($payload['guardrails'], JSON_THROW_ON_ERROR),
        'configuration' => json_encode($payload['configuration'], JSON_THROW_ON_ERROR),
        'metadata' => json_encode($payload['metadata'], JSON_THROW_ON_ERROR),
        'input' => json_encode($payload['input'], JSON_THROW_ON_ERROR),
        'output' => json_encode($payload['output'], JSON_THROW_ON_ERROR),
        'raw_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
      ]);
    $log->save();

    $loaded = AiInteractionLog::load($log->id());

    $this->assertNotNull($loaded);
    $this->assertSame(self::MOCK_PROVIDER_ID, $loaded->get('provider')->value);
    $this->assertSame(self::MOCK_MODEL_ID, $loaded->get('model')->value);
    $this->assertSame('ai.post_generate_response', $loaded->get('event_name')->value);
    $this->assertSame('chat', $loaded->get('operation_type')->value);
    $this->assertSame('ai_observability', $loaded->get('channel')->value);
    $this->assertSame('req_123', $loaded->getProviderRequestId());
    $this->assertSame('parent_req_123', $loaded->get('provider_parent_request_id')->value);
    $this->assertSame('100', $loaded->get('input_tokens')->value);
    $this->assertSame('50', $loaded->get('output_tokens')->value);
    $this->assertSame('150', $loaded->get('total_tokens')->value);
    $this->assertSame('10', $loaded->get('cached_tokens')->value);
    $this->assertTrue($loaded->get('reasoning_tokens')->isEmpty());
    $this->assertSame('https://example.com/node/add/news', $loaded->get('request_uri')->value);
    $this->assertSame('https://example.com/admin/content', $loaded->get('referer')->value);
    $this->assertSame('https://example.com', $loaded->get('base_url')->value);
    $this->assertSame('7', $loaded->get('user_id')->value);
    $this->assertSame('0.0.0.0', $loaded->get('ip')->value);
    $this->assertSame('info', $loaded->get('severity')->value);
    $this->assertSame('1718000000', $loaded->get('event_timestamp')->value);
    $this->assertSame(['drafting', 'content_creation'], json_decode($loaded->get('tags')->value, TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertSame($payload, json_decode($loaded->get('raw_payload')->value, TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertSame(hash('sha256', 'req_123:1718000000'), $loaded->getIdempotencyKey());

    $entity_type = $this->container->get('entity_type.manager')->getDefinition('ai_interaction_log');
    $this->assertFalse($entity_type->hasKey('bundle'));
    $this->assertFalse($loaded->hasField('session_id'));
    $this->assertFalse($loaded->hasField('node_id'));
    $this->assertFalse($loaded->hasField('revision_id'));
  }

  /**
   * Tests that post-generate response events are persisted once.
   */
  public function testPostGenerateResponseEventPersistsInteractionLog(): void {
    $request = Request::create(
      'https://example.com/node/add/news',
      'POST',
      server: [
        'HTTP_REFERER' => 'https://example.com/admin/content',
        'REMOTE_ADDR' => '0.0.0.0',
      ],
    );
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $input = new ChatInput([
      new ChatMessage('system', 'You draft content.'),
      new ChatMessage('user', 'Create a news draft.'),
    ]);
    $output = new ChatOutput(
      normalized: new ChatMessage('assistant', 'Draft content.'),
      rawOutput: ['id' => 'provider-response-1'],
      metadata: ['finish_reason' => 'stop'],
      tokenUsage: new TokenUsageDto(
        input: 100,
        output: 50,
        total: 150,
        reasoning: 7,
        cached: 10,
      ),
    );
    $event = new PostGenerateResponseEvent(
      requestThreadId: 'req_456',
      providerId: self::MOCK_PROVIDER_ID,
      operationType: 'chat',
      configuration: ['temperature' => 0.2],
      input: $input,
      modelId: self::MOCK_MODEL_ID,
      output: $output,
      tags: ['drafting', 'content_creation'],
      debugData: ['attempt' => 1],
      metadata: ['route' => 'oe_ai_assistant.plugin.dispatch'],
    );
    $event->setRequestParentId('parent_req_456');

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->dispatch($event, PostGenerateResponseEvent::EVENT_NAME);
    $dispatcher->dispatch($event, PostGenerateResponseEvent::EVENT_NAME);

    $ids = $this->container->get('entity_type.manager')
      ->getStorage('ai_interaction_log')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_request_id', 'req_456')
      ->execute();

    $this->assertCount(1, $ids);

    /** @var \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface $log */
    $log = AiInteractionLog::load(reset($ids));
    $this->assertSame(self::MOCK_PROVIDER_ID, $log->get('provider')->value);
    $this->assertSame(self::MOCK_MODEL_ID, $log->get('model')->value);
    $this->assertSame('ai.post_generate_response', $log->get('event_name')->value);
    $this->assertSame('ai_observability', $log->get('channel')->value);
    $this->assertSame('chat', $log->get('operation_type')->value);
    $this->assertSame('req_456', $log->getProviderRequestId());
    $this->assertSame('parent_req_456', $log->get('provider_parent_request_id')->value);
    $this->assertSame('100', $log->get('input_tokens')->value);
    $this->assertSame('50', $log->get('output_tokens')->value);
    $this->assertSame('150', $log->get('total_tokens')->value);
    $this->assertSame('10', $log->get('cached_tokens')->value);
    $this->assertSame('7', $log->get('reasoning_tokens')->value);
    $this->assertSame('https://example.com/node/add/news', $log->get('request_uri')->value);
    $this->assertSame('https://example.com/admin/content', $log->get('referer')->value);
    $this->assertSame('https://example.com', $log->get('base_url')->value);
    $this->assertSame('0.0.0.0', $log->get('ip')->value);

    $raw_payload = json_decode($log->get('raw_payload')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['drafting', 'content_creation'], $raw_payload['tags']);
    $this->assertSame(['temperature' => 0.2], $raw_payload['configuration']);
    $this->assertSame(['attempt' => 1], $raw_payload['metadata']['debug_data']);
    $this->assertSame('system' . "\n" . 'You draft content.' . "\n" . 'user' . "\n" . 'Create a news draft.' . "\n", $raw_payload['input']['string']);
    $this->assertSame('provider-response-1', $raw_payload['output']['data']['rawOutput']['id']);
  }

  /**
   * Tests that streamed responses are saved after the stream is complete.
   */
  public function testStreamingResponsePersistsCompletedInteractionLog(): void {
    $input = new ChatInput([
      new ChatMessage('user', 'Create a news draft.'),
    ]);
    $stream_iterator = new ReplayedChatMessageIterator(
      new class implements \IteratorAggregate {

        /**
         * {@inheritdoc}
         */
        public function getIterator(): \Traversable {
          return new \EmptyIterator();
        }

      },
    );
    $stream_iterator->setFirstMessage('');
    $streaming_output = new ChatOutput(
      normalized: $stream_iterator,
      rawOutput: [],
      metadata: [],
    );
    $completed_output = new ChatOutput(
      normalized: new ChatMessage('assistant', 'Completed streamed draft.'),
      rawOutput: ['id' => 'provider-response-stream'],
      metadata: ['finish_reason' => 'stop'],
      tokenUsage: new TokenUsageDto(total: 42),
    );

    $post_generate_event = new PostGenerateResponseEvent(
      requestThreadId: 'req_stream',
      providerId: self::MOCK_PROVIDER_ID,
      operationType: 'chat',
      configuration: [],
      input: $input,
      modelId: self::MOCK_MODEL_ID,
      output: $streaming_output,
    );
    $post_streaming_event = new PostStreamingResponseEvent(
      requestThreadId: 'req_stream',
      providerId: self::MOCK_PROVIDER_ID,
      operationType: 'chat',
      configuration: [],
      input: $input,
      modelId: self::MOCK_MODEL_ID,
      output: $completed_output,
    );

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->dispatch($post_generate_event, PostGenerateResponseEvent::EVENT_NAME);

    $storage = $this->container->get('entity_type.manager')
      ->getStorage('ai_interaction_log');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_request_id', 'req_stream')
      ->execute();
    $this->assertCount(0, $ids);

    $dispatcher->dispatch($post_streaming_event, PostStreamingResponseEvent::EVENT_NAME);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_request_id', 'req_stream')
      ->execute();
    $this->assertCount(1, $ids);

    /** @var \Drupal\oe_ai_assistant\Entity\AiInteractionLogInterface $log */
    $log = AiInteractionLog::load(reset($ids));
    $this->assertSame('ai.post_streaming_response', $log->get('event_name')->value);
    $this->assertSame('42', $log->get('total_tokens')->value);

    $raw_payload = json_decode($log->get('raw_payload')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Completed streamed draft.', $raw_payload['output']['data']['normalized']['text']);
  }

}
