<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service;

use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
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
   */
  public function __construct(
    private readonly EntityJsonSchemaComposer $schemaComposer,
    #[Autowire(service: 'plugin.manager.ai_agents')]
    private readonly AiAgentManager $aiAgentManager,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(
    UiMessageStreamInterface $stream,
    array $history,
    string $entityTypeId,
    string $bundle,
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
        );

        $parsed = $stream->extractJson($fullText);
        if (is_array($parsed)) {
          $results[$stepId] = $parsed;
          if ($stepId === 'main_fields') {
            $mainFieldsResult = $fullText;
          }
        }

        $plan[$index]['status'] = 'done';
        $stream->customEvent('data-plan', $plan);
      }
      catch (\Exception $e) {
        $this->logger->error('Sub-agent @step failed: @error', [
          '@step' => $stepId,
          '@error' => $e->getMessage(),
        ]);
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
   */
  private function runSubAgent(
    string $stepId,
    array $schemaSlice,
    string $conversationContext,
    string $mainFieldsResult,
  ): string {
    $agent = $this->aiAgentManager
      ->createInstance('oe_content_drafter');

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
    if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
      return $agent->solve() ?? '';
    }
    if ($solvability === AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION) {
      return $agent->answerQuestion() ?? '';
    }
    return '';
  }

}
