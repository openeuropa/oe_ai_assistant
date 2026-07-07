<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Kernel;

use Drupal\ai\AiProviderInterface;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentResponseEvent;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\EventSubscriber\SubAgentMessageSubscriber;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Drupal\user\Entity\User;
use Psr\Log\AbstractLogger;

/**
 * Kernel tests for the sub-agent transcript subscriber.
 *
 * Covers the top sub-agent (tag correlated) and a sub-agent that a sub-agent
 * spawns (runner-id correlated), asserting each nests under the correct parent.
 *
 * @group oe_ai_assistant
 */
class SubAgentMessageSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Drupal core.
    'datetime',
    'field',
    'file',
    'filter',
    'node',
    'options',
    'system',
    'text',
    'user',
    'workflows',
    'content_moderation',
    'serialization',
    'image',
    'link',
    'taxonomy',
    // Contrib.
    'ai',
    'ai_agents',
    'entity_reference_revisions',
    'inline_entity_form',
    'key',
    'paragraphs',
    // This project.
    'oe_ai_assistant',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_conversation_message');
  }

  /**
   * Tests the top sub-agent records a system and assistant row under its turn.
   */
  public function testTopSubAgentRecordsUnderTaggedParent(): void {
    $host = User::create(['name' => 'session-host']);
    $host->save();
    $parent = $this->container->get(MessageRecorderInterface::class)
      ->recordUser($host, 'Draft a news article.', 1);

    $agent = $this->mockAgent(
      SubAgentMessageSubscriber::correlationTags('title', $host, $parent),
    );
    $event = $this->responseEvent($agent, 'You are a content generator.', '{"title":[{"value":"X"}]}', 'R1', NULL);
    $this->container->get('event_dispatcher')->dispatch($event, AgentResponseEvent::EVENT_NAME);

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    $children = $storage->loadByProperties(['parent' => $parent->id()]);
    $roles = array_map(fn ($m) => $m->getRole(), $children);
    sort($roles);
    $this->assertSame(['assistant', 'system'], $roles);

    // The assistant row records the agent, provider, and model actually used.
    $assistant = $this->assistantChild($children);
    $this->assertSame('title', $assistant->get('agent_id')->value);
    $this->assertSame('mistral', $assistant->get('provider')->value);
    $this->assertSame('mistral-large-latest', $assistant->get('model')->value);
  }

  /**
   * Tests that a sub-agent spawning a sub-agent nests under its caller.
   *
   * The ai_agents framework records each child's caller runner id, and a
   * parent dispatches its response event before it spawns children. The
   * subscriber maps a recorded agent's runner id to its assistant row, so the
   * nested agent parents under the top sub-agent's row, not under the top
   * draft_content turn.
   */
  public function testNestedSubAgentParentsUnderItsCaller(): void {
    $host = User::create(['name' => 'session-host']);
    $host->save();
    $draftTurn = $this->container->get(MessageRecorderInterface::class)
      ->recordUser($host, 'Draft a news article.', 1);
    $dispatcher = $this->container->get('event_dispatcher');

    // Event 1: the orchestrator's top sub-agent (tagged), runner "R1", no
    // caller.
    $top = $this->mockAgent(
      SubAgentMessageSubscriber::correlationTags('title', $host, $draftTurn),
    );
    $dispatcher->dispatch(
      $this->responseEvent($top, 'SP top', '{"title":[{"value":"X"}]}', 'R1', NULL),
      AgentResponseEvent::EVENT_NAME,
    );

    // Event 2: a sub-agent the top agent spawned (untagged), runner "R2",
    // caller "R1".
    $nested = $this->mockAgent([]);
    $dispatcher->dispatch(
      $this->responseEvent($nested, 'SP nested', '{"body":[{"value":"Y"}]}', 'R2', 'R1'),
      AgentResponseEvent::EVENT_NAME,
    );

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();

    // The top agent's rows parent under the draft_content turn.
    $topChildren = $storage->loadByProperties(['parent' => $draftTurn->id()]);
    $topRoles = array_map(fn ($m) => $m->getRole(), $topChildren);
    sort($topRoles);
    $this->assertSame(['assistant', 'system'], $topRoles, 'The top sub-agent nests under the draft turn.');
    $topAssistant = $this->assistantChild($topChildren);

    // The nested agent's rows parent under the top agent's assistant row.
    $nestedChildren = $storage->loadByProperties(['parent' => $topAssistant->id()]);
    $nestedRoles = array_map(fn ($m) => $m->getRole(), $nestedChildren);
    sort($nestedRoles);
    $this->assertSame(['assistant', 'system'], $nestedRoles, 'The nested sub-agent nests under its caller.');
  }

  /**
   * Tests a tagged response with a missing parent is logged and dropped.
   *
   * When the orchestrator has set correlation tags but the referenced parent
   * turn cannot be loaded, that is an upstream inconsistency: the subscriber
   * records nothing but surfaces a warning rather than failing silently.
   */
  public function testTaggedResponseWithMissingParentLogsWarning(): void {
    $host = User::create(['name' => 'session-host']);
    $host->save();

    $logger = new class() extends AbstractLogger {
      /**
       * The captured log records, each as a message plus its context.
       *
       * @var array<int, array{message: string, context: array}>
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($logger);

    // The parent tag points at a message id that does not exist.
    $ghostParent = $this->createMock(AiConversationMessageInterface::class);
    $ghostParent->method('id')->willReturn('999999');
    $agent = $this->mockAgent(
      SubAgentMessageSubscriber::correlationTags('title', $host, $ghostParent),
    );
    $this->container->get('event_dispatcher')->dispatch(
      $this->responseEvent($agent, 'SP', '{"title":[{"value":"X"}]}', 'R1', NULL),
      AgentResponseEvent::EVENT_NAME,
    );

    // Nothing is recorded under the missing parent, and a warning naming the
    // missing parent message is logged.
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_conversation_message');
    $storage->resetCache();
    $this->assertSame([], $storage->loadByProperties(['parent' => 999999]));
    $this->assertNotEmpty(array_filter(
      $logger->records,
      fn (array $record) => str_contains($record['message'], 'tagged sub-agent response')
        && ($record['context']['@missing'] ?? '') === 'parent message',
    ));
  }

  /**
   * Builds a mocked config agent with the given extra tags.
   *
   * Provider and model are stubbed so the subscriber can read them back.
   */
  private function mockAgent(array $tags): ConfigAiAgentInterface {
    $provider = $this->createMock(AiProviderInterface::class);
    $provider->method('getPluginId')->willReturn('mistral');
    $agent = $this->createMock(ConfigAiAgentInterface::class);
    $agent->method('getExtraTags')->willReturn($tags);
    $agent->method('getAiProvider')->willReturn($provider);
    $agent->method('getModelName')->willReturn('mistral-large-latest');
    return $agent;
  }

  /**
   * Builds a response event for an agent, with its runner and caller ids.
   */
  private function responseEvent(ConfigAiAgentInterface $agent, string $systemPrompt, string $json, string $runnerId, ?string $callerId): AgentResponseEvent {
    $output = new ChatOutput(new ChatMessage('assistant', $json), [], [], new TokenUsageDto(10, 5, 15));
    return new AgentResponseEvent($agent, $systemPrompt, 'oe_content_drafter', 'task', [], $output, 1, $runnerId, NULL, $callerId);
  }

  /**
   * Returns the single assistant row from a set of loaded messages.
   */
  private function assistantChild(array $messages): AiConversationMessageInterface {
    foreach ($messages as $message) {
      if ($message->getRole() === AiConversationMessageInterface::ROLE_ASSISTANT) {
        return $message;
      }
    }
    $this->fail('No assistant row found.');
  }

}
