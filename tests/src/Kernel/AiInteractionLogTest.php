<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiInteractionLog;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for AI interaction logs.
 */
#[Group('oe_ai_assistant')]
class AiInteractionLogTest extends KernelTestBase {

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
    'key',
    'node',
    'oe_ai_assistant',
    'options',
    'serialization',
    'system',
    'text',
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
      'provider' => 'openai',
      'model' => 'gpt-4.1-mini',
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
      'ip' => '203.0.113.10',
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
    $this->assertSame('openai', $loaded->get('provider')->value);
    $this->assertSame('gpt-4.1-mini', $loaded->get('model')->value);
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
    $this->assertSame('203.0.113.10', $loaded->get('ip')->value);
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

}
