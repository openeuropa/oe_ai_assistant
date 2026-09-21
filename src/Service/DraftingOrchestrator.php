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
use Drupal\oe_ai_assistant\Service\Drafting\EditorialContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dispatches sub-agents per schema group and consolidates results.
 */
class DraftingOrchestrator implements DraftingOrchestratorInterface {

  /**
   * How many times a group is asked before it counts as failed.
   */
  private const MAX_ATTEMPTS = 5;

  /**
   * How many schema problems are quoted back to the model at a time.
   */
  private const MAX_REPORTED_ERRORS = 10;

  /**
   * Constructs a DraftingOrchestrator.
   *
   * @param \Drupal\oe_ai_assistant\Service\DraftingSchemaProviderInterface $schemaProvider
   *   The provider for the (optionally template-pruned) field groups.
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $aiAgentManager
   *   The agent plugin manager for sub-agent instances.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\oe_ai_assistant\Service\MessageRecorderInterface $messageRecorder
   *   The message recorder, used to record sub-agent failures as error turns.
   * @param \Drupal\oe_ai_assistant\Service\StructuredOutputValidatorInterface $outputValidator
   *   The validator that checks each answer against the group's schema.
   */
  public function __construct(
    private readonly DraftingSchemaProviderInterface $schemaProvider,
    #[Autowire(service: 'plugin.manager.ai_agents')]
    private readonly AiAgentManager $aiAgentManager,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerInterface $logger,
    private readonly MessageRecorderInterface $messageRecorder,
    private readonly StructuredOutputValidatorInterface $outputValidator,
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
    ?EditorialContext $context = NULL,
  ): array {
    $groups = $this->schemaProvider->groups(
      $entityTypeId, $bundle, $context?->templateId
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
        [$parsed, $fullText] = $this->draftGroup(
          $stepId, $group['schemaSlice'], $stream,
          $conversationContext, $mainFieldsResult,
          $host, $parent, $context,
        );

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
        // The model may answer with keys outside the schema slice; keep only
        // the group's fields so the draft stays saveable.
        $consolidated = array_merge(
          $consolidated,
          array_intersect_key($results[$stepId], array_flip($group['fieldNames'])),
        );
        continue;
      }
      foreach ($group['fieldNames'] as $fieldName) {
        if (array_key_exists($fieldName, $results[$stepId])) {
          $consolidated[$fieldName] = $results[$stepId][$fieldName];
        }
        elseif (array_is_list($results[$stepId])) {
          // Some providers omit the single field wrapper and return its item
          // list directly. Accept that legacy shape, but never treat an
          // unrelated associative object as this field: doing so can pass
          // another group's fields to InlineEntityHydrator.
          $consolidated[$fieldName] = $results[$stepId];
        }
      }
    }
    return $consolidated;
  }

  /**
   * Asks a sub-agent for a group until its answer fits the group's schema.
   *
   * Structured output makes a provider declare the shape it will return; it
   * does not make it keep that promise. Each answer is therefore validated,
   * and a failing one is handed back to the model as the list of problems it
   * has to fix, in the schema's own vocabulary. An answer that is not JSON
   * at all is treated as one more failed attempt.
   *
   * @param string $stepId
   *   The schema group being drafted.
   * @param array $schemaSlice
   *   The JSON schema the answer has to satisfy.
   * @param \Drupal\oe_ai_assistant\Service\UiMessageStreamInterface $stream
   *   The stream, used to pull the JSON object out of the answer text.
   * @param string $conversationContext
   *   The conversation so far, as passed to the sub-agent.
   * @param string $mainFieldsResult
   *   The already drafted main fields, empty for the main fields group.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The session the draft belongs to.
   * @param \Drupal\oe_ai_assistant\Entity\AiConversationMessageInterface|null $parent
   *   The turn sub-agent messages are recorded under, or NULL not to record.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext|null $context
   *   The editorial context to inject into the sub-agent prompt.
   *
   * @return array
   *   The accepted answer as [decoded fields, raw answer text].
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SubAgentException
   *   When no attempt produced an answer matching the schema.
   */
  private function draftGroup(
    string $stepId,
    array $schemaSlice,
    UiMessageStreamInterface $stream,
    string $conversationContext,
    string $mainFieldsResult,
    EntityInterface $host,
    ?AiConversationMessageInterface $parent,
    ?EditorialContext $context,
  ): array {
    $correction = '';
    $errors = [];

    for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
      $fullText = $this->runSubAgent(
        $stepId, $schemaSlice,
        $conversationContext, $mainFieldsResult,
        $host, $parent, $context, $correction,
      );

      $parsed = $stream->extractJson($fullText);
      $errors = $parsed === NULL
        ? ['the returned object: the answer is not valid JSON']
        : $this->outputValidator->validate($parsed, $schemaSlice);

      if ($errors === []) {
        if ($attempt > 1) {
          $this->logger->notice('Sub-agent @step matched its schema on attempt @attempt.', [
            '@step' => $stepId,
            '@attempt' => $attempt,
          ]);
        }
        return [$parsed, $fullText];
      }

      $this->logger->warning('Sub-agent @step attempt @attempt of @max does not match its schema: @errors', [
        '@step' => $stepId,
        '@attempt' => $attempt,
        '@max' => self::MAX_ATTEMPTS,
        '@errors' => implode(' | ', $errors),
      ]);

      $correction = $this->buildCorrection($errors);
    }

    throw new SubAgentException(sprintf(
      'The "%s" sub-agent did not match its schema in %d attempts: %s',
      $stepId,
      self::MAX_ATTEMPTS,
      implode('; ', array_slice($errors, 0, self::MAX_REPORTED_ERRORS)),
    ));
  }

  /**
   * Turns schema problems into the correction the next attempt receives.
   *
   * @param array $errors
   *   The problems reported for the previous answer.
   *
   * @return string
   *   The prompt fragment listing what has to change.
   */
  private function buildCorrection(array $errors): string {
    $listed = array_slice($errors, 0, self::MAX_REPORTED_ERRORS);
    $lines = implode("\n", array_map(
      static fn (string $error): string => "- $error",
      $listed,
    ));
    $remaining = count($errors) - count($listed);
    if ($remaining > 0) {
      $lines .= sprintf("\n- and %d further problems of the same kind", $remaining);
    }

    return "Your previous answer did not match the schema you were given:\n"
      . $lines . "\n"
      . "Answer again with a single JSON object that satisfies the schema, "
      . "correcting exactly the problems listed above and keeping the rest of "
      . "the content as it was.";
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
    ?EditorialContext $context = NULL,
    string $correction = '',
  ): string {
    $agent = $this->aiAgentManager
      ->createInstance('oe_content_drafter');

    // Tag the agent so its response is correlated to the session and parent
    // turn. Skipped when no parent is available: drafting still runs, the
    // sub-agent transcript is simply not recorded.
    if ($parent !== NULL) {
      $agent->setUserInterface(NULL, SubAgentMessageSubscriber::correlationTags($stepId, $host, $parent));
    }

    // Inject the shared editorial context prompt into the sub-agent system
    // prompt so the agents that produce the field values honour it. The
    // template is not injected: it already acts through the pruned schema.
    $contextPrompt = $context?->toPrompt() ?? '';
    if ($contextPrompt !== '') {
      $agentEntity = $agent->getAiAgentEntity();
      $agentEntity->set(
        'system_prompt',
        $agentEntity->get('system_prompt') . "\n\n" . $contextPrompt . "\n",
      );
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
    if ($correction !== '') {
      $taskPrompt .= "\n\n" . $correction;
    }

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
