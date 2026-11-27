<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\AmendContent\Nodes;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Unicode;
use Drupal\oe_ai_assistant\Entity\ActivityType;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Neuron\ToolWorkflowInterface;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\AmendContent\Events\AmendContentRequestEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Nodes\GroupDraftingTrait;
use Drupal\oe_ai_assistant\Utility\DraftVersion;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Amends one stored draft into its next minor version, a field group at a time.
 *
 * Like DraftContentNode, the work happens here and not inside the tool the
 * model called, so we can yield while it runs and the editor sees which
 * group is being rewritten. Each named group goes through draft_group again,
 * the same workflow drafting_draft_content uses, seeded with the values the
 * source draft holds for it. A group the call leaves out keeps those values.
 */
final class AmendContentNode extends Node {

  use GroupDraftingTrait;

  /**
   * Builds the node.
   *
   * @param string $callId
   *   The tool call this amendment answers.
   * @param string $sourceVersion
   *   The version of the draft to amend.
   * @param array $groups
   *   The field group IDs to amend. Empty amends every group.
   * @param string $brief
   *   What the editor asked to change, as the agent passed it to the tool.
   *   Empty when the agent sent none.
   */
  public function __construct(
    private readonly string $callId,
    private readonly string $sourceVersion,
    private readonly array $groups,
    private readonly string $brief,
  ) {}

  /**
   * Amends the draft this call asked for, and reports the outcome.
   */
  public function __invoke(AmendContentRequestEvent $event, WorkflowState $state, DraftContentResources $resources): \Generator {
    $callId = $this->callId;
    $sourceVersion = DraftVersion::normalize($this->sourceVersion);
    $source = $resources->session->getDraftRun($sourceVersion);

    if ($source === NULL || ($source['fields'] ?? []) === []) {
      $message = sprintf('Draft %s does not exist in this session.', DraftVersion::display($sourceVersion));
      yield new CustomStreamEvent('amend-failed', [
        'callId' => $callId,
        'sourceVersion' => $sourceVersion,
        'error' => $message,
      ]);

      $this->recordFailure($resources, $callId, $sourceVersion, $message);

      $state->set(ToolWorkflowInterface::RESULT_KEY, [
        'error' => $message,
        'version' => $sourceVersion,
        'status' => 'failed',
      ]);

      return new StopEvent();
    }

    // Draft against the schema groups the source draft carries, not the
    // session's current ones: a template edit after the draft shouldn't
    // change what a revision of that draft asks for. A draft stored before
    // this key existed falls back to the session's live groups.
    $groups = (array) ($source['schemaGroups'] ?? $resources->session->getFieldGroups());
    $availableIds = array_column($groups, 'groupId');
    $named = array_values(array_filter(
      array_map('strval', $this->groups),
      static fn (string $id): bool => $id !== '',
    ));
    $targetIds = $named !== [] ? array_intersect($named, $availableIds) : $availableIds;
    $unknownIds = $named !== [] ? array_diff($named, $availableIds) : [];

    // Fail on any unknown group ID instead of silently amending only the
    // known ones.
    if ($targetIds === [] || $unknownIds !== []) {
      $message = $targetIds === []
        ? sprintf(
          'None of the named groups exist in draft %s. Available groups: %s.',
          DraftVersion::display($sourceVersion),
          implode(', ', $availableIds),
        )
        : sprintf(
          'Draft %s has no group(s) named %s. Available groups: %s.',
          DraftVersion::display($sourceVersion),
          implode(', ', $unknownIds),
          implode(', ', $availableIds),
        );
      yield new CustomStreamEvent('amend-failed', [
        'callId' => $callId,
        'sourceVersion' => $sourceVersion,
        'error' => $message,
      ]);

      $this->recordFailure($resources, $callId, $sourceVersion, $message);

      $state->set(ToolWorkflowInterface::RESULT_KEY, [
        'error' => $message,
        'version' => $sourceVersion,
        'status' => 'failed',
      ]);

      return new StopEvent();
    }

    // nextMinorVersion() isn't stable across retries: it returns the next
    // minor up once a draft is stored under this major. If this step is
    // re-run, we'd tell the editor "Draft 1.1" and then store it as "Draft
    // 1.2". Memoizing per call id pins the version to the first reading.
    $version = (string) $this->memoize(
      'version.' . $callId,
      fn (): string => $resources->session->nextMinorVersion($sourceVersion),
    );
    $run = sprintf('amend_%s.%s', $version, Crypt::randomBytesBase64(4));
    $context = (array) ($source['context'] ?? []);
    $fields = (array) ($source['fields'] ?? []);

    $progress = [];
    foreach ($groups as $group) {
      $groupId = (string) $group['groupId'];
      $progress[$groupId] = [
        'id' => $groupId,
        'label' => (string) ($group['label'] ?? $groupId),
        'status' => in_array($groupId, $targetIds, TRUE) ? 'pending' : 'carried',
      ];
    }

    yield new CustomStreamEvent('amend-started', [
      'version' => $version,
      'sourceVersion' => $sourceVersion,
      'callId' => $callId,
      'context' => $context,
      'groups' => array_values($progress),
    ]);

    $total = count($targetIds);
    $index = 0;
    $failed = '';

    try {
      foreach ($groups as $group) {
        $groupId = (string) $group['groupId'];
        if (!in_array($groupId, $targetIds, TRUE)) {
          continue;
        }

        $index++;
        $failed = $groupId;

        // On a retry, reuse the answer a group already gave and don't report
        // it as "drafting" again. We can't wrap the drafting in memoize(),
        // because the block has to yield and a closure can't yield on behalf
        // of this generator. So we look the answer up by hand and only draft
        // on a miss.
        $answer = $this->recallMemo('group.' . $callId . '.' . $groupId);

        if (!is_array($answer)) {
          yield $this->progress($callId, $group, 'drafting', $index, $total);

          $existing = array_intersect_key($fields, array_flip($group['fieldNames']));
          // The brief is all the drafter gets about the request. It runs on
          // its own thread, and replaying the conversation here would send
          // it after documents the editor has since removed.
          $task = "What the editor asked for:\n" . $this->brief . "\n\n"
            . "The group's current values:\n" . json_encode($existing) . "\n\n"
            . 'Amend the fields of the provided schema to follow that request.'
            . ' Keep anything it does not mention as it already is.';

          $answer = $this->draftGroup($resources, $group, $run, $task);
          $answer = $this->memoize('group.' . $callId . '.' . $groupId, static fn (): array => $answer);
        }

        foreach ($group['fieldNames'] as $fieldName) {
          if (array_key_exists($fieldName, $answer)) {
            $fields[$fieldName] = $answer[$fieldName];
          }
        }

        yield $this->progress($callId, $group, 'drafted', $index, $total);
        $progress[$groupId]['status'] = 'drafted';
        $failed = '';
      }

      $this->storeDraft($resources, [
        'version' => $version,
        'run' => $run,
        'status' => 'drafted',
        'context' => $context,
        'groups' => array_values($progress),
        'schemaGroups' => $groups,
        'fields' => $fields,
        'amends' => $sourceVersion,
      ]);
      // A resumed run replays this node with its groups already memoized,
      // and the record is append-only. A second entry would tell the
      // editor the same draft was amended twice.
      $this->memoize('recorded.' . $callId, function () use ($resources, $version, $sourceVersion): bool {
        $resources->session->recordActivity(
          ActivityType::DraftGenerated,
          'Draft %s amended from draft %s.',
          [DraftVersion::display($version), DraftVersion::display($sourceVersion)],
        );

        return TRUE;
      });

      yield new CustomStreamEvent('amended-fields', [
        'version' => $version,
        'sourceVersion' => $sourceVersion,
        'callId' => $callId,
        'status' => 'drafted',
        'context' => $context,
        'groups' => array_values($progress),
        'error' => '',
        'fields' => $fields,
        'createdAt' => date('c'),
      ]);

      $result = [
        'draft' => sprintf('Draft %s', DraftVersion::display($version)),
        'version' => $version,
        'amends' => $sourceVersion,
      ];
    }
    catch (\Throwable $e) {
      $resources->logger->error('Amending @group of session @session failed in run @run: @message', [
        '@group' => $failed === '' ? 'the set' : sprintf('the "%s" group', $failed),
        '@session' => (string) $resources->session->id(),
        '@run' => $run,
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);

      $message = match (TRUE) {
        $e instanceof SchemaRetriesExhaustedException => $e->getMessage(),
        $failed === '' => 'The amendment could not be written.',
        default => 'The group could not be amended.',
      };

      if ($failed !== '') {
        yield $this->progress($callId, ['groupId' => $failed], 'failed', $index, $total);
        $progress[$failed]['status'] = 'failed';
        $progress[$failed]['error'] = $message;
      }

      $this->storeDraft($resources, [
        'version' => $version,
        'run' => $run,
        'status' => 'failed',
        'context' => $context,
        'groups' => array_values($progress),
        'schemaGroups' => $groups,
        'fields' => [],
        'amends' => $sourceVersion,
        // Group failures show on the group's own row. A failure that isn't
        // tied to a group has no row, so it goes here for the card header.
        'error' => $failed === '' ? $message : '',
      ]);
      // A schema failure names every field of the template that missed, and
      // GetSessionActivityNeuronAgent reads the whole activity record to
      // answer one question about the session. So the entry keeps the
      // opening of the reason and no more.
      $this->recordFailure($resources, $callId, $sourceVersion, Unicode::truncate($message, 200, TRUE, TRUE), $failed);

      yield new CustomStreamEvent('amend-failed', [
        'version' => $version,
        'sourceVersion' => $sourceVersion,
        'callId' => $callId,
        'group' => $failed,
        'error' => $message,
      ]);

      $result = ['error' => $message, 'group' => $failed, 'version' => $version];
    }

    $state->set(ToolWorkflowInterface::RESULT_KEY, $result);

    return new StopEvent();
  }

  /**
   * Records that this call's amendment failed, once per call.
   *
   * A resumed run replays this node, and the activity record is
   * append-only, so the memo keeps a retry from telling the editor the same
   * amendment failed twice.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   * @param string $callId
   *   The call this amendment answers.
   * @param string $sourceVersion
   *   The version the call asked to amend.
   * @param string $message
   *   The reason the amendment failed.
   * @param string $group
   *   The group the failure is tied to, or empty when it belongs to no
   *   group.
   */
  private function recordFailure(DraftContentResources $resources, string $callId, string $sourceVersion, string $message, string $group = ''): void {
    $this->memoize('recorded.' . $callId, function () use ($resources, $sourceVersion, $message, $group): bool {
      if ($group === '') {
        $resources->session->recordActivity(
          ActivityType::DraftFailed,
          'Amending draft %s failed: %s',
          [DraftVersion::display($sourceVersion), $message],
        );
      }
      else {
        $resources->session->recordActivity(
          ActivityType::DraftFailed,
          'Amending draft %s failed in the "%s" group: %s',
          [DraftVersion::display($sourceVersion), $group, $message],
        );
      }

      return TRUE;
    });
  }

  /**
   * Reports where one group of this amendment stands.
   *
   * @param string $callId
   *   The call this amendment answers.
   * @param array $group
   *   The group being amended, with its id and its label.
   * @param string $status
   *   What is happening to it: drafting, drafted or failed.
   * @param int $index
   *   Which named group this is, counting from one.
   * @param int $total
   *   How many groups this amendment names.
   *
   * @return \NeuronAI\Agent\Adapters\Events\ActivityStreamEvent
   *   The progress event.
   */
  private function progress(string $callId, array $group, string $status, int $index, int $total): ActivityStreamEvent {
    $groupId = (string) $group['groupId'];

    return new ActivityStreamEvent(
      id: sprintf('amend:%s.%s', $callId, $groupId),
      type: 'amending',
      data: [
        'callId' => $callId,
        'group' => $groupId,
        'label' => (string) ($group['label'] ?? $groupId),
        'status' => $status,
        'index' => $index,
        'total' => $total,
      ],
    );
  }

}
