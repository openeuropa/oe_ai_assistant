<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\Entity\EntityInterface;
use Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface;
use Drupal\oe_ai_assistant\EventSubscriber\SubAgentMessageSubscriber;
use Drupal\oe_ai_assistant\Exception\SubAgentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dispatches sub-agents per schema group and consolidates results.
 */
class DraftingOrchestrator implements DraftingOrchestratorInterface {

  /**
   * Constructs a DraftingOrchestrator.
   *
   * @param \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer $schemaComposer
   *   The schema composer for splitting fields into groups.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $aiAgentManager
   *   The agent plugin manager for sub-agent instances.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $messageRecorder
   *   The message recorder, used to record sub-agent failures as error turns.
   */
  public function __construct(
    private readonly EntityJsonSchemaComposer $schemaComposer,
    #[Autowire(service: 'plugin.manager.ai_agents')]
    private readonly AiAgentManager $aiAgentManager,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
    private readonly MessageRecorderInterface $messageRecorder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(
    UiMessageStreamInterface $stream,
    array $history,
    string $entityTypeId,
    string $bundle,
    EntityInterface $host,
    ?AiConversationMessageInterface $parent = NULL,
  ): array {
    $groups = $this->schemaComposer->splitSchemaIntoGroups(
      $entityTypeId, $bundle
    );

    if (empty($groups)) {
      $stream->textDelta('No fields available for drafting.');
      return [];
    }

    // Emit initial plan (all pending).
    $plan = array_map(fn($g) => [
      'stepId' => $g['groupId'],
      'label' => $g['label'],
      'status' => 'pending',
    ], $groups);
    $stream->customEvent('data-plan', $plan);

    // Build conversation context for sub-agents.
    $conversationContext = '';
    foreach ($history as $msg) {
      $conversationContext .= $msg->getRole() . ': '
        . $msg->getText() . "\n";
    }

    $results = [];
    $mainFieldsResult = '';

    foreach ($groups as $index => $group) {
      $stepId = $group['groupId'];

      $plan[$index]['status'] = 'in_progress';
      $stream->customEvent('data-plan', $plan);

      try {
        $fullText = $this->runSubAgent(
          $stepId, $group['schemaSlice'],
          $conversationContext, $mainFieldsResult,
          $host, $parent,
        );

        $parsed = $stream->extractJson($fullText);
        if ($parsed === NULL) {
          throw new SubAgentException(sprintf(
            'The "%s" sub-agent returned a response that is not valid JSON.',
            $stepId,
          ));
        }
        $results[$stepId] = $parsed;
        if ($stepId === 'main_fields') {
          $mainFieldsResult = $fullText;
        }

        $plan[$index]['status'] = 'done';
        $stream->customEvent('data-plan', $plan);
      }
      catch (\Exception $e) {
        $this->logger->error('Sub-agent @step failed: @error', [
          '@step' => $stepId,
          '@error' => $e->getMessage(),
        ]);
        // No response event fires for a group that produced nothing, so record
        // the error as a turn under the draft_content parent to keep the
        // transcript complete. Every failure mode reaches this one path.
        // Without a parent no sub-agent transcript is being recorded (see
        // runSubAgent()), and an error row recorded anyway would dangle at the
        // root of the tree; skip it and rely on the log and the stream.
        if ($parent !== NULL) {
          $this->messageRecorder->recordError($host, $e->getMessage(), $stepId, $parent);
        }
        $plan[$index]['status'] = 'error';
        $stream->customEvent('data-plan', $plan);
        $stream->error($e->getMessage(), $stepId);
      }
    }

    // Consolidate and emit. The caller streams and records the confirmation
    // so it persists in the transcript.
    $consolidated = $this->consolidate($groups, $results);
    $stream->customEvent('data-drafted-fields', $consolidated);

    return $consolidated;
  }

  /**
   * Merges sub-agent results into a flat fields map.
   *
   * Main fields merge flat; entity reference groups merge by
   * field name with a fallback for unwrapped results.
   */
  private function consolidate(array $groups, array $results): array {
    $consolidated = [];
    foreach ($groups as $group) {
      $stepId = $group['groupId'];
      if (!isset($results[$stepId])) {
        continue;
      }
      if ($stepId === 'main_fields') {
        $consolidated = array_merge($consolidated, $results[$stepId]);
        continue;
      }
      foreach ($group['fieldNames'] as $fieldName) {
        $consolidated[$fieldName] = $results[$stepId][$fieldName]
          ?? $results[$stepId];
      }
    }
    return $consolidated;
  }

  /**
   * Runs a single sub-agent for a schema group.
   *
   * When a parent turn is given, the agent is tagged so the response subscriber
   * can record the sub-agent's system prompt and answer nested under it.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SubAgentException
   *   When the agent yields no answer or an empty response.
   */
  private function runSubAgent(
    string $stepId,
    array $schemaSlice,
    string $conversationContext,
    string $mainFieldsResult,
    EntityInterface $host,
    ?AiConversationMessageInterface $parent,
  ): string {
    $agent = $this->aiAgentManager
      ->createInstance('oe_content_drafter');

    // Tag the agent so its response is correlated to the session and parent
    // turn. Skipped when no parent is available: drafting still runs, the
    // sub-agent transcript is simply not recorded.
    if ($parent !== NULL) {
      $agent->setUserInterface(NULL, SubAgentMessageSubscriber::correlationTags($stepId, $host, $parent));
    }

    $agent->getAiAgentEntity()
      ->set('structured_output_enabled', TRUE);
    $agent->getAiAgentEntity()
      ->set('structured_output_schema', json_encode([
        'name' => $stepId,
        'schema' => $schemaSlice,
      ]));

    $taskPrompt = "Conversation context:\n$conversationContext\n";
    if ($stepId !== 'main_fields' && $mainFieldsResult !== '') {
      $taskPrompt .= "Main fields already generated:\n"
        . $mainFieldsResult . "\n\n";
    }
    $taskPrompt .= "Generate content for the fields in the "
      . "provided schema. Follow the conversation context.";

    $agent->setTask(new Task($taskPrompt));

    $solvability = $agent->determineSolvability();
    $fullText = match ($solvability) {
      AiAgentInterface::JOB_SOLVABLE => $agent->solve() ?? '',
      AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => $agent->answerQuestion() ?? '',
      // Any other verdict means the agent produced no answer. A failing
      // provider call lands here rather than as an exception: ai_agents
      // catches it, dispatches AgentFinishedExecutionEvent and returns
      // JOB_NOT_SOLVABLE. That makes an infrastructure failure look identical
      // to a refusal, so treat both as a failed group instead of returning an
      // empty string the caller would silently record as a success.
      default => throw new SubAgentException(sprintf(
        'The "%s" sub-agent returned no answer (solvability %d); the provider call may have failed.',
        $stepId,
        $solvability,
      )),
    };

    if (trim($fullText) === '') {
      throw new SubAgentException(sprintf(
        'The "%s" sub-agent returned an empty response.',
        $stepId,
      ));
    }

    return $fullText;
  }

}
