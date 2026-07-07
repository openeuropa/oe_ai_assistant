<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\EventSubscriber;

use Drupal\ai_agents\Event\AgentResponseEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oe_ai_assistant\Service\MessageRecorderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records drafting sub-agent turns as nested conversation messages.
 *
 * The top sub-agent is correlated by the extra tags the orchestrator sets on it
 * before solve(): a session tag (host "type:id"), a parent tag (the
 * draft_content turn id), and an agent tag (agent id). The tag names are
 * namespaced (see the TAG_ constants) so they cannot clash with other systems'
 * extra tags. A sub-agent that a sub-agent spawns carries no tags, but the
 * ai_agents framework records its caller runner id; this subscriber maps each
 * recorded agent's runner id to its assistant row, so a nested agent parents
 * under its caller's row. Untagged responses with an unknown caller are
 * ignored.
 */
class SubAgentMessageSubscriber implements EventSubscriberInterface {

  /**
   * Extra-tag prefix carrying the host entity as "type:id".
   *
   * The trailing colon is part of the prefix, so the remainder is exactly the
   * value (which itself contains the "type:id" colon).
   */
  public const TAG_SESSION = 'ai_conversation_message_session:';

  /**
   * Extra-tag prefix carrying the parent conversation message id.
   */
  public const TAG_PARENT = 'ai_conversation_message_parent:';

  /**
   * Extra-tag prefix carrying the sub-agent id.
   */
  public const TAG_AGENT = 'ai_conversation_message_agent:';

  /**
   * Maps an agent runner id to its recorded assistant row id and host.
   *
   * Request scoped and keyed by per-run UUIDs, so entries never collide across
   * runs. It lets a nested sub-agent find the parent row of its caller.
   *
   * @var array<string, array{message: int, host: \Drupal\Core\Entity\EntityInterface}>
   */
  protected array $runnerRows = [];

  /**
   * Constructs a SubAgentMessageSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $messageRecorder
   *   The message recorder.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly MessageRecorderInterface $messageRecorder,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AgentResponseEvent::EVENT_NAME => 'onAgentResponse'];
  }

  /**
   * Records the sub-agent's system prompt and response under its parent.
   *
   * @param \Drupal\ai_agents\Event\AgentResponseEvent $event
   *   The agent response event.
   */
  public function onAgentResponse(AgentResponseEvent $event): void {
    [$host, $parent] = $this->resolveHostAndParent($event);
    if ($host === NULL || $parent === NULL) {
      // Not one of our orchestrated sub-agents, nor within their subtree.
      return;
    }

    $agentId = $this->tagValue($event->getAgent()->getExtraTags(), self::TAG_AGENT)
      ?? $event->getAgentId();

    // Provider and model are resolved on the agent by response time, so record
    // what the sub-agent actually ran on rather than empty strings.
    $agent = $event->getAgent();
    $provider = $agent->getAiProvider()?->getPluginId() ?? '';
    $model = $agent->getModelName() ?? '';

    $this->messageRecorder->recordSystem($host, $event->getSystemPrompt(), $agentId, $parent);
    $assistant = $this->messageRecorder->recordAssistant($host, $event->getResponse(), $agentId, $provider, $model, $parent);

    // Register this agent's row so any sub-agent it spawns parents under it.
    $this->runnerRows[$event->getAgentRunnerId()] = [
      'message' => (int) $assistant->id(),
      'host' => $host,
    ];
  }

  /**
   * Resolves the host entity and parent row for an agent response.
   *
   * A tagged agent (the orchestrator's top sub-agent) uses its session and
   * parent tags. An untagged agent whose caller runner id is already recorded
   * is a nested sub-agent: it inherits the caller's host and parents under the
   * caller's assistant row.
   *
   * @param \Drupal\ai_agents\Event\AgentResponseEvent $event
   *   The agent response event.
   *
   * @return array
   *   A two-element list of the host entity (or NULL) and the parent message
   *   (or NULL) when the response is not one of ours.
   */
  protected function resolveHostAndParent(AgentResponseEvent $event): array {
    $extraTags = $event->getAgent()->getExtraTags();
    $session = $this->tagValue($extraTags, self::TAG_SESSION);
    $parentId = $this->tagValue($extraTags, self::TAG_PARENT);
    if ($session !== NULL && $parentId !== NULL) {
      [$hostType, $hostId] = explode(':', $session) + [NULL, NULL];
      $host = $hostType && $hostId
        ? $this->entityTypeManager->getStorage($hostType)->load($hostId)
        : NULL;
      $parent = $this->entityTypeManager->getStorage('ai_conversation_message')
        ->load($parentId);
      // The orchestrator explicitly tagged this agent, so a host or parent that
      // cannot be loaded is an upstream inconsistency (a stale or wrong id). Do
      // not halt, but surface it instead of dropping the turn silently.
      if ($host === NULL || $parent === NULL) {
        $missing = [];
        if ($host === NULL) {
          $missing[] = 'host entity';
        }
        if ($parent === NULL) {
          $missing[] = 'parent message';
        }
        $this->logger->warning('Dropping a tagged sub-agent response for session "@session" and parent "@parent": @missing not found.', [
          '@session' => $session,
          '@parent' => $parentId,
          '@missing' => implode(' and ', $missing),
        ]);
      }
      return [$host ?: NULL, $parent ?: NULL];
    }

    // Nested sub-agent: inherit host and parent from the caller's recorded row.
    $callerId = $event->getCallerId();
    if ($callerId !== NULL && isset($this->runnerRows[$callerId])) {
      $caller = $this->runnerRows[$callerId];
      $parent = $this->entityTypeManager->getStorage('ai_conversation_message')
        ->load($caller['message']);
      // The caller was recorded moments ago, so a missing parent row here means
      // it was deleted mid-run; keep track of it rather than dropping silently.
      if ($parent === NULL) {
        $this->logger->warning('Dropping a nested sub-agent response for caller runner "@caller": parent message @message not found.', [
          '@caller' => $callerId,
          '@message' => $caller['message'],
        ]);
        return [NULL, NULL];
      }
      return [$caller['host'], $parent];
    }

    return [NULL, NULL];
  }

  /**
   * Extracts the value of the first extra tag with the given prefix.
   *
   * Only tags carrying one of this subscriber's own prefixes are read; any
   * other extra tags on the agent are left untouched. The prefix includes its
   * trailing colon, so the returned value is the remainder verbatim (which for
   * the session tag is itself a "type:id" pair).
   *
   * @param string[] $extraTags
   *   The agent's extra tags.
   * @param string $prefix
   *   One of the TAG_ prefix constants.
   *
   * @return string|null
   *   The value after the prefix, or NULL when no tag carries it.
   */
  protected function tagValue(array $extraTags, string $prefix): ?string {
    foreach ($extraTags as $tag) {
      if (str_starts_with($tag, $prefix)) {
        return substr($tag, strlen($prefix));
      }
    }
    return NULL;
  }

}
