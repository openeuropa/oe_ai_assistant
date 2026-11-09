<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Nodes;

use Drupal\Component\Utility\Crypt;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\EditorialAssistantMessage;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\ValidateNode;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\DraftingRequestedEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\Events\DraftStoredEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources;
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Writes a draft for every call of a reply, a field group at a time.
 *
 * The drafting runs here rather than inside the tool the model called, so it
 * can yield while it works and the editor sees which group it is writing. Each
 * group is drafted by the drafting workflow, which checks the answer
 * against the schema of the group and asks again while it does not match.
 */
final class DraftContentNode extends Node {

  /**
   * The group that carries the title and the summary of the content.
   */
  private const MAIN_GROUP = 'main_fields';

  /**
   * Writes one draft for every call that asked, and reports each one.
   */
  public function __invoke(DraftingRequestedEvent $event, WorkflowState $state, EditorialResources $resources): \Generator {
    // A draft takes its number from the drafts the session stores, and it
    // stores nothing until it writes its last group. Two drafts that run
    // at the same time would both answer Draft 1, and the editor would
    // open one of the two. So this loop writes the draft of one call at a
    // time.
    $results = [];
    foreach ($event->callIds as $callId) {
      $result = yield from $this->draft($resources, $state, $callId);
      $results[$callId] = ['result' => $result];
    }
    $state->set('results', $results);

    return new DraftStoredEvent();
  }

  /**
   * Writes the draft that one call asked for.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   * @param \NeuronAI\Workflow\WorkflowState $state
   *   The state of the run, which holds what the editor asked for.
   * @param string $callId
   *   The call this draft answers.
   *
   * @return \Generator
   *   Yields the progress of the draft. Returns what the model receives,
   *   which is the name of the draft or what stopped it.
   */
  private function draft(EditorialResources $resources, WorkflowState $state, string $callId): \Generator {
    $groups = $resources->session->getFieldGroups();
    $total = count($groups);
    // The run already told the editor the name of this draft. A step that
    // runs again reads nextVersion() a second time, and that reading
    // answers a higher number once the session stores a draft. The draft
    // would land under a name the editor never saw. So the memo keeps the
    // first reading, under the id of the call.
    $version = (int) $this->memoize(
      'version.' . $callId,
      fn (): int => $resources->session->nextVersion(),
    );
    $run = $this->runId($version);
    $answers = [];
    $index = 0;
    $failed = '';

    // Read before the first group, so the stored draft and the card the
    // editor watches carry the same snapshot of what the editor set up. A
    // reading in the catch could throw a second time.
    $context = $this->snapshot($resources);

    // The editor's card draws its rows from this list. The list travels
    // before the first group is drafted, so the card shows every group of the
    // content type from the start and fills them in as the run reaches them.
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
      // A content type with no field group assembles a draft of no fields.
      // The editor is then told a draft is ready, and opens an empty form.
      // So the run fails and the model reports it.
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

        // A group this node already drafted keeps that draft, and replays
        // none of its progress. A closure cannot yield into this generator,
        // so the node reads the recorded value back rather than wrapping the
        // drafting in memoize().
        $fields = $this->recallMemo('group.' . $callId . '.' . $groupId);

        if (!is_array($fields)) {
          yield $this->progress($callId, $group, 'drafting', $index, $total);
          $fields = $this->draftGroup($resources, $group, $run, $this->task($resources, $state, $answers));
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

      // The field values reach no model, so the pane cannot read them off the
      // tool result. They go out as their own part, which is what shows the
      // draft without waiting for a reload. The part is a convenience: a
      // reload reads the draft back from the drafts thread.
      yield new CustomStreamEvent('drafted-fields', [
        'version' => $version,
        'callId' => $callId,
        'status' => 'drafted',
        'context' => $context,
        'groups' => array_values($progress),
        'error' => '',
        'fields' => $fields,
        // The row the draft sits in is stamped by the site, and a reload
        // reads the time back off it. The browser clock would disagree with
        // that, so the time the site kept travels with the draft.
        'createdAt' => date('c'),
      ]);

      // A draft holds every field of the content type, and the agent replays
      // its thread on every turn. So the model is told the name of the draft,
      // and the fields stay in the drafts thread.
      $result = ['draft' => sprintf('Draft %d', $version), 'version' => $version];
    }
    catch (\Throwable $e) {
      // The set is incomplete, so this run stores no field. The model has to
      // learn which group stopped it: an editor told a draft is ready, who
      // then opens half a content type, learns nothing.
      $resources->logger->error('Drafting @group of session @session failed in run @run: @message', [
        '@group' => $failed === '' ? 'the set' : sprintf('the "%s" group', $failed),
        '@session' => (string) $resources->session->id(),
        '@run' => $run,
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);

      // A raw message can carry a gateway response, and the agent repeats to
      // the editor what it reads. A schema miss names the fields of the
      // editor's own template, so that one is passed on.
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
      // is stored the way a run that worked is. The record holds no field,
      // which is what keeps it out of the drafts the editor can open.
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

    return $result;
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
   *
   * @return \NeuronAI\Agent\Adapters\Events\ActivityStreamEvent
   *   The progress event.
   */
  private function progress(string $callId, array $group, string $status, int $index, int $total): ActivityStreamEvent {
    $groupId = (string) $group['groupId'];

    // Two drafts of one reply write the same groups. An event replaces the
    // row of the card that carries its id. An id of the group alone would
    // give both drafts one set of rows. The second draft would then reset
    // the rows the first draft filled. So the id names the call and the
    // group.
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
      ],
    );
  }

  /**
   * Runs the drafter of one group and reports the values it wrote.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   * @param array $group
   *   The group definition, with its id and its schema slice.
   * @param string $run
   *   The run this draft belongs to.
   * @param string $task
   *   What to tell the drafter.
   *
   * @return array
   *   The field values the drafter wrote.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException
   *   When no answer of the drafter matched the group schema.
   */
  private function draftGroup(EditorialResources $resources, array $group, string $run, string $task): array {
    $state = $resources->workflows->createWorkflow('draft_group', [], [
      'session' => $resources->session,
      'group' => (string) $group['groupId'],
      'schema' => (array) $group['schemaSlice'],
      'task' => $task,
      'run' => $run,
    ])->run();

    return (array) $state->get(ValidateNode::FIELDS_KEY, []);
  }

  /**
   * Names this run, so two runs of one draft never share a conversation.
   *
   * A retried step drafts the version it took the first time. A name built
   * from the version alone would name the drafter thread of the attempt
   * that failed. The drafter would answer from a conversation that already
   * failed. So a random segment separates the two runs. The version ties
   * the run to the draft it wrote.
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
   * A draft holds every field of the content type, which no model needs to
   * read back. Nothing runs an agent on this thread, so the draft is as
   * large as the content type needs. A reader asks for one draft, so the
   * message names its version and the store records it on the row.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   * @param array $draft
   *   The record of the run: its version, the run that wrote it, its status,
   *   the snapshot of what the editor set up, the groups with the status each
   *   one reached, and the field values.
   */
  private function storeDraft(EditorialResources $resources, array $draft): void {
    $message = (new EditorialAssistantMessage(json_encode($draft)))
      ->setDraftVersion((string) $draft['version']);

    $resources->store->append($resources->session->draftsThread(), $message);
  }

  /**
   * What the editor set up, as a draft of this run records it.
   *
   * Ids travel with the labels read at request time, so a draft keeps what the
   * editor saw even if a term or a template is renamed later. The file name
   * and the extracted text stay out: a draft records which documents were
   * used, not their contents.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares.
   *
   * @return array
   *   The snapshot.
   */
  private function snapshot(EditorialResources $resources): array {
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
   * A model does not always key its answer the way the schema asks. Two
   * shapes come back instead. A main group carries fields of another group,
   * and a reference group answers a bare list. This repairs both.
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
        elseif (array_is_list($answer) && count($group['fieldNames']) === 1) {
          // A model sometimes answers a bare list where the schema asks for
          // an object keyed by field. A group of one field takes the list as
          // the value of that field. A list names no field of a larger
          // group, so such a group takes nothing.
          $fields[$fieldName] = $answer;
        }
      }
    }

    return $fields;
  }

  /**
   * Builds what this run tells one group's drafter.
   *
   * A drafter reads its own thread only. Without a task it writes about a
   * subject of its own. So the task carries the conversation of the session,
   * what the editor asked for, and what the earlier groups wrote.
   *
   * @param \Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Editorial\EditorialResources $resources
   *   What this run shares, which answers what the session said so far.
   * @param \NeuronAI\Workflow\WorkflowState $state
   *   The state of the run, which holds what the editor asked for.
   * @param array $answers
   *   What the groups drafted so far answered, keyed by group id.
   *
   * @return string
   *   The task.
   */
  private function task(EditorialResources $resources, WorkflowState $state, array $answers): string {
    $task = '';

    // A drafter writes one group and holds no conversation of its own. It
    // reads what the editor wants from this task alone. So the task opens
    // with the turns of the session, which carry no draft.
    $turns = $resources->session->conversationTurns();
    if ($turns !== []) {
      $task = "The conversation so far:\n" . implode("\n", array_map(
        static fn (array $turn): string => sprintf(
          '%s: %s',
          $turn['role'] === 'user' ? 'Editor' : 'Assistant',
          $turn['text'],
        ),
        $turns,
      )) . "\n\n";
    }

    $task .= "The editor asked for this content:\n" . (string) $state->get('task') . "\n";

    // A later group must fit the title and the summary the main group wrote,
    // so the task carries them.
    if (isset($answers[self::MAIN_GROUP])) {
      $task .= "Main fields already generated:\n"
        . json_encode($answers[self::MAIN_GROUP]) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
