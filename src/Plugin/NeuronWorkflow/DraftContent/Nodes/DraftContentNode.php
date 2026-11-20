<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Nodes;

use Drupal\Component\Utility\Crypt;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Neuron\ToolWorkflowInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Events\DraftContentRequestEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\DraftNode;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\ValidateNode;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Writes one draft of a session, a field group at a time.
 *
 * The editor watches the groups fill in, so this node yields where each one
 * stands as it reaches it.
 */
final class DraftContentNode extends Node {

  /**
   * The group that carries the title and the summary of the content.
   */
  private const MAIN_GROUP = 'main_fields';

  /**
   * Builds the node.
   *
   * @param string $callId
   *   The tool call this draft answers. It names the progress the editor
   *   watches, and the records that keep a replayed step from drafting twice.
   * @param string $brief
   *   What the editor asked for, as the agent passed it to the tool. Empty
   *   when the agent sent none.
   */
  public function __construct(
    private readonly string $callId,
    private readonly string $brief,
  ) {}

  /**
   * Writes the draft, a field group at a time, and records the outcome.
   *
   * Each group is drafted by the draft_group workflow, which checks the
   * answer against the schema of the group and asks again while it does not
   * match.
   */
  public function __invoke(
    DraftContentRequestEvent $event,
    WorkflowState $state,
    DraftContentResources $resources,
  ): \Generator {
    $callId = $this->callId;
    $groups = $resources->session->getFieldGroups();
    $total = count($groups);
    // The run already told the editor the name of this draft. A replayed
    // step calls nextVersion() again, which returns a higher number once a
    // draft has been stored, so we would promise "Draft 3" and then store
    // "Draft 4". Memoizing per call id pins the version to the first
    // reading.
    $version = (int) $this->memoize(
      'version.' . $callId,
      fn (): int => $resources->session->nextVersion(),
    );
    $run = $this->runId($version);
    // The conversation can't change while the groups are drafted, so reading
    // it per group would repeat the same query. It is read once here, for the
    // whole draft.
    $conversation = $this->conversation($resources);
    $answers = [];
    $index = 0;
    $failed = '';

    // The card the editor watches and the stored draft must show the same
    // setup, and snapshot() can throw. So it is read once here, before the
    // first group, and the catch block reuses what it returned.
    $context = $this->snapshot($resources);

    // The editor should see every group of the content type from the start,
    // not one row turning up at a time. So the rows of the card are built
    // here and sent with draft-started, before the first group is drafted.
    $progress = [];
    foreach ($groups as $group) {
      $groupId = (string) $group['groupId'];
      $progress[$groupId] = [
        'id' => $groupId,
        'label' => (string) ($group['label'] ?? $groupId),
        'status' => 'pending',
      ];
    }

    yield new CustomStreamEvent('draft-started', [
      'version' => $version,
      'callId' => $callId,
      'context' => $context,
      'groups' => array_values($progress),
    ]);

    try {
      // A content type with no field group would produce a draft with no
      // fields. The editor would be told a draft is ready and would open an
      // empty form. So the run fails here and the model reports it.
      if ($groups === []) {
        throw new \RuntimeException(sprintf(
          'The "%s" content type has no field group to draft.',
          $resources->session->getContentType(),
        ));
      }

      foreach ($groups as $group) {
        $index++;
        $groupId = (string) $group['groupId'];
        $failed = $groupId;

        // On a retry, a group that was already drafted keeps that draft and
        // is not reported as "drafting" again. We can't wrap the drafting in
        // memoize(), because it has to yield and a closure can't yield on
        // behalf of this generator. So the recorded value is read back here.
        $fields = $this->recallMemo('group.' . $callId . '.' . $groupId);

        if (!is_array($fields)) {
          yield $this->progress($callId, $group, 'drafting', $index, $total);
          $fields = yield from $this->draftGroup($resources, $group, $run, $this->task($conversation, $answers), $callId, $index, $total);
          $fields = $this->memoize('group.' . $callId . '.' . $groupId, static fn (): array => $fields);
        }

        yield $this->progress($callId, $group, 'drafted', $index, $total);
        $progress[$groupId]['status'] = 'drafted';
        $answers[$groupId] = $fields;
        $failed = '';
      }

      $fields = $this->assemble($groups, $answers);
      $this->storeDraft($resources, [
        'version' => $version,
        'run' => $run,
        'status' => 'drafted',
        'context' => $context,
        'groups' => array_values($progress),
        'fields' => $fields,
      ]);

      // The tool result holds no field values, so the pane can't read them
      // off it. They are sent as an event of their own instead, which is what
      // shows the draft without a reload. After a reload the pane reads the
      // draft back from the drafts thread.
      yield new CustomStreamEvent('drafted-fields', [
        'version' => $version,
        'callId' => $callId,
        'status' => 'drafted',
        'context' => $context,
        'groups' => array_values($progress),
        'error' => '',
        'fields' => $fields,
        // After a reload the pane shows the timestamp of the row the draft
        // was stored in, and the browser clock would disagree with it. So
        // server time is sent here, and the two match.
        'createdAt' => date('c'),
      ]);

      // A draft holds every field of the content type, and the agent re-sends
      // its whole thread on every turn, so fields in a tool result would be
      // paid for on every later request. The model is only told the name of
      // the draft, and the fields stay in the drafts thread.
      $result = ['draft' => sprintf('Draft %d', $version), 'version' => $version];
    }
    catch (\Throwable $e) {
      // The editor only ever reads the short message built below, so the
      // group, the session and the run go to the log for whoever has to work
      // out why the draft failed.
      $resources->logger->error('Drafting @group of session @session failed in run @run: @message', [
        '@group' => $failed === '' ? 'the set' : sprintf('the "%s" group', $failed),
        '@session' => (string) $resources->session->id(),
        '@run' => $run,
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);

      // An exception message can carry a gateway response, and the agent
      // repeats to the editor whatever this result holds. A schema failure
      // only names fields of the editor's own template, so that one is passed
      // on and the rest become a fixed sentence.
      $message = match (TRUE) {
        $e instanceof SchemaRetriesExhaustedException => $e->getMessage(),
        $failed === '' => 'The draft could not be written.',
        default => 'The group could not be drafted.',
      };

      if ($failed !== '') {
        yield $this->progress($callId, ['groupId' => $failed], 'failed', $index, $total);
        $progress[$failed]['status'] = 'failed';
        $progress[$failed]['error'] = $message;
      }

      // The editor reads the card again after a reload, so a run that failed
      // is stored the way a run that worked is. Its status is "failed", which
      // is how listDrafts() and getDraftContent() keep it out of the drafts
      // the editor can open.
      $this->storeDraft($resources, [
        'version' => $version,
        'run' => $run,
        'status' => 'failed',
        'context' => $context,
        'groups' => array_values($progress),
        'fields' => [],
        // A failure that belongs to no group has no row to carry it, so the
        // record carries it for the header of the card.
        'error' => $failed === '' ? $message : '',
      ]);

      yield new CustomStreamEvent('draft-failed', [
        'version' => $version,
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
   * Reports where one group of one draft stands.
   *
   * @param string $callId
   *   The call whose draft this group belongs to.
   * @param array $group
   *   The group being drafted, with its id and its label.
   * @param string $status
   *   What is happening to it: drafting, drafted or failed.
   * @param int $index
   *   Which group of the set this is, counting from one.
   * @param int $total
   *   How many groups the draft holds.
   * @param array $detail
   *   How far the drafter has written: the attempt and the characters.
   *
   * @return \NeuronAI\Agent\Adapters\Events\ActivityStreamEvent
   *   The progress event.
   */
  private function progress(string $callId, array $group, string $status, int $index, int $total, array $detail = []): ActivityStreamEvent {
    $groupId = (string) $group['groupId'];

    // Two drafts of one reply write the same groups, and the card replaces
    // the row whose id the event carries. An id built from the group alone
    // would give both drafts one set of rows, and the second draft would
    // reset the rows the first one filled. So the id names the call too.
    return new ActivityStreamEvent(
      id: sprintf('draft:%s.%s', $callId, $groupId),
      type: 'drafting',
      data: [
        'callId' => $callId,
        'group' => $groupId,
        'label' => (string) ($group['label'] ?? $groupId),
        'status' => $status,
        'index' => $index,
        'total' => $total,
      ] + $detail,
    );
  }

  /**
   * Runs the drafter of one group and reports the values it wrote.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   * @param array $group
   *   The group definition, with its id and its schema slice.
   * @param string $run
   *   The run this draft belongs to.
   * @param string $task
   *   What to tell the drafter.
   * @param string $callId
   *   The call whose draft this group belongs to.
   * @param int $index
   *   Which group of the set this is, counting from one.
   * @param int $total
   *   How many groups the draft holds.
   *
   * @return \Generator
   *   Yields how far the drafter has written. Returns the field values it
   *   wrote.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException
   *   When no answer of the drafter matched the group schema.
   */
  private function draftGroup(DraftContentResources $resources, array $group, string $run, string $task, string $callId, int $index, int $total): \Generator {
    $events = $resources->workflows->createWorkflow('draft_group', [], [
      'session' => $resources->session,
      'group' => (string) $group['groupId'],
      'schema' => (array) $group['schemaSlice'],
      'task' => $task,
      'run' => $run,
    ])->events();

    foreach ($events as $event) {
      // This step commits only after the last group, and a group can take
      // minutes. So the lease of the run is renewed as the drafter reports.
      // heartbeat() writes only once half the lease has passed.
      $this->heartbeat();

      // Only the progress of the drafter reaches the editor, as an update of
      // the row of its group.
      if ($event instanceof CustomStreamEvent && $event->name === DraftNode::PROGRESS_EVENT && is_array($event->value)) {
        yield $this->progress($callId, $group, 'drafting', $index, $total, [
          'attempt' => (int) ($event->value['attempt'] ?? 1),
          'chars' => (int) ($event->value['chars'] ?? 0),
        ]);
      }
    }

    return (array) $events->getReturn()->get(ValidateNode::FIELDS_KEY, []);
  }

  /**
   * Names this run, so two attempts at one draft never share a conversation.
   *
   * A replayed step drafts the version it took the first time. A name built
   * from the version alone would point at the drafter thread of the attempt
   * that failed, and the drafter would answer from that conversation. So a
   * random segment separates the two, and the version ties the run to the
   * draft it wrote.
   *
   * @param int $version
   *   The version this run drafts.
   *
   * @return string
   *   The run id, which names the drafter conversations of this run.
   */
  private function runId(int $version): string {
    return sprintf('draft_%d.%s', $version, Crypt::randomBytesBase64(4));
  }

  /**
   * Keeps the draft in a thread of its own.
   *
   * No model has to read a draft back, and no agent runs on this thread, so
   * a record can be as large as the content type needs. A reader asks for one
   * draft, so the message names its version and the store records it on the
   * row.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   * @param array $draft
   *   The record of the run: its version, the run that wrote it, its status,
   *   the snapshot of what the editor set up, the groups with the status each
   *   one reached, and the field values.
   */
  private function storeDraft(DraftContentResources $resources, array $draft): void {
    $message = (new EditorialAssistantMessage(json_encode($draft)))
      ->setDraftVersion((string) $draft['version']);

    $resources->store->append($resources->session->draftsThread(), $message);
  }

  /**
   * What the editor set up, as a draft of this run records it.
   *
   * The labels are stored next to the ids, as they read now, so a draft keeps
   * what the editor saw even if a term or a template is renamed later. The
   * file name and the extracted text are dropped, because a draft records
   * which documents were used and not what they contain.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   *
   * @return array
   *   The snapshot.
   */
  private function snapshot(DraftContentResources $resources): array {
    return [
      'tone' => $resources->session->getTone(),
      'template' => $resources->session->getTemplate(),
      'documents' => array_map(static function (array $document): array {
        unset($document['filename'], $document['extract']);
        return $document;
      }, $resources->session->getDocuments()),
    ];
  }

  /**
   * Merges what each group answered into one field map.
   *
   * A model does not always key its answer the way the schema asks, and the
   * main group can come back with fields that belong to another group. So we
   * take from each answer only the fields of the group that answered, and
   * drop the rest.
   *
   * @param array $groups
   *   The field groups of the run, each with groupId and fieldNames.
   * @param array $answers
   *   What each group answered, keyed by group id.
   *
   * @return array
   *   The field values, keyed by field name.
   */
  private function assemble(array $groups, array $answers): array {
    $fields = [];
    foreach ($groups as $group) {
      $answer = $answers[$group['groupId']];
      if ($group['groupId'] === self::MAIN_GROUP) {
        $fields = array_merge($fields, array_intersect_key($answer, array_flip($group['fieldNames'])));
        continue;
      }

      foreach ($group['fieldNames'] as $fieldName) {
        if (array_key_exists($fieldName, $answer)) {
          $fields[$fieldName] = $answer[$fieldName];
        }
      }
    }

    return $fields;
  }

  /**
   * Builds what this run tells one group's drafter.
   *
   * A drafter only sees its own thread, so without a task it would write
   * about a subject of its own choosing. The task gives it the conversation
   * from the session and whatever the earlier groups wrote.
   *
   * @param string $conversation
   *   The conversation block from conversation(), empty when the session has
   *   no turns yet.
   * @param array $answers
   *   What the groups drafted so far wrote, keyed by group id.
   *
   * @return string
   *   The task.
   */
  private function task(string $conversation, array $answers): string {
    $task = $conversation;

    // The conversation block only has turns that have been closed. The turn
    // that asked for this draft is still open while the tool runs, so only
    // the brief has the editor's request.
    if ($this->brief !== '') {
      $task .= "What the editor asked for:\n" . $this->brief . "\n\n";
    }

    // A later group must fit the title and the summary the main group wrote,
    // so the task carries them.
    if (isset($answers[self::MAIN_GROUP])) {
      $task .= "Main fields already generated:\n"
        . json_encode($answers[self::MAIN_GROUP]) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

  /**
   * Formats the session's conversation for a drafter's prompt.
   *
   * This reads the editorial thread only. The drafts don't show up in it,
   * because each group's drafter writes to a thread of its own.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources $resources
   *   What this run shares.
   *
   * @return string
   *   The conversation block, empty when the session has no turns yet.
   */
  private function conversation(DraftContentResources $resources): string {
    $turns = $resources->session->conversationTurns();
    if ($turns === []) {
      return '';
    }

    return "The conversation so far:\n" . implode("\n", array_map(
      static fn (array $turn): string => sprintf(
        '%s: %s',
        $turn['role'] === 'user' ? 'Editor' : 'Assistant',
        $turn['text'],
      ),
      $turns,
    )) . "\n\n";
  }

}
