<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Channel\ChannelAwareInterface;
use Drupal\ai_neuron\Traits\ChannelAwareTrait;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\ValidateNode;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drafts every field group of the session and stores the draft.
 *
 * One call writes one draft. The groups, their order and the assembly are
 * decided here rather than by the model, which only decides when a draft is
 * due. The result names the draft and carries nothing of its content, so a
 * schema of any size costs the conversation the same. The draft itself goes
 * to a thread of its own, which no agent reads back.
 */
#[NeuronTool(
  id: 'drafting_draft_content',
  description: 'Drafts the content of every field group and stores it as the next draft.'
  . ' Call it once, when the editor is ready for a draft. The result carries'
  . ' the draft name ("Draft 1", "Draft 2", and so on) and its version;'
  . ' drafting_get_history lists the drafts of this session.',
  label: new TranslatableMarkup('Draft content'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'thread' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Conversation thread'),
    ),
  ],
)]
final class DraftContentNeuronTool extends DraftingToolBase implements ChannelAwareInterface {

  use ChannelAwareTrait;

  /**
   * The group that carries the title and the summary of the content.
   */
  private const MAIN_GROUP = 'main_fields';

  /**
   * What this call answered, so one turn writes one draft.
   */
  private ?string $result = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronWorkflowManagerInterface $workflows,
    private readonly MessageStoreInterface $store,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Drafts the whole set and reports the draft it stored.
   *
   * A model can call one tool twice in a turn, and the second call would
   * draft the whole set again. So a later call answers with the draft the
   * first one wrote.
   */
  public function __invoke(): string {
    if ($this->result !== NULL) {
      return $this->result;
    }

    $session = $this->getContextValue('session');
    assert($session instanceof ContentCreationSessionInterface);
    $run = '';
    $groupId = '';

    // A failure of the session, the store or the assembly would reach the
    // editor as the message the site saw. One catch covers the whole run, so
    // every failure answers the model through failed().
    try {
      $groups = $session->getFieldGroups();
      if ($groups === []) {
        throw new \RuntimeException(sprintf(
          'The "%s" content type has no field group to draft.',
          $session->getContentType(),
        ));
      }

      $version = (int) $session->nextVersion()['version'];
      $run = $this->runId($version);
      $conversation = $this->conversation();
      $answers = [];

      // A tool cannot stream to its own agent, so per-group progress leaves
      // through the channel instead: one mutated plan, re-sent in full on
      // every group's status transition, mirroring what the deleted
      // DraftingOrchestrator sent through the old UiMessageStream.
      $plan = array_map(static fn (array $g): array => [
        'stepId' => $g['groupId'],
        'label' => $g['label'],
        'status' => 'pending',
      ], $groups);
      $this->channel?->send(new ProtocolEvent('data-plan', ['data' => $plan]));

      foreach ($groups as $index => $group) {
        $groupId = (string) $group['groupId'];
        $plan[$index]['status'] = 'in_progress';
        $this->channel?->send(new ProtocolEvent('data-plan', ['data' => $plan]));
        try {
          $answers[$groupId] = $this->draftGroup($group, $run, $this->task($conversation, $answers));
        }
        catch (\Throwable $e) {
          $plan[$index]['status'] = 'error';
          $this->channel?->send(new ProtocolEvent('data-plan', ['data' => $plan]));
          throw $e;
        }
        $plan[$index]['status'] = 'done';
        $this->channel?->send(new ProtocolEvent('data-plan', ['data' => $plan]));
      }

      // The drafted field values reach the editor's pane as a
      // data-drafted-fields part, because they are no longer in the tool
      // result; captured once so the channel and the drafts thread send and
      // store the same value. The channel carries the version and the
      // context snapshot alongside the fields — the same shape
      // getMessages() joins onto the tool-call result on reload — so the
      // frontend can show the draft the moment this run finishes, without
      // waiting for a reload to pick it up from the drafts thread.
      $fields = $this->assemble($groups, $answers);
      $context = $this->draftContext();
      $this->storeDraft($session, $version, $run, $context, $fields);
      $this->channel?->send(new ProtocolEvent('data-drafted-fields', [
        'data' => ['version' => $version, 'context' => $context, 'fields' => $fields],
      ]));

      // A draft holds every field of the content type, and this agent
      // replays its thread on every turn. So the result names the draft,
      // and the fields stay in the drafts thread.
      return $this->result = json_encode([
        'draft' => sprintf('Draft %d', $version),
        'version' => $version,
      ]);
    }
    catch (\Throwable $e) {
      // The set is incomplete, so this run writes no draft. The model needs
      // the group that stopped it, because an editor who opens a draft of
      // half a content type learns nothing.
      return $this->failed($e, $groupId, $run, (string) $session->id());
    }
  }

  /**
   * Reports a group the run could not write.
   *
   * @param \Throwable $exception
   *   What stopped the group.
   * @param string $groupId
   *   The group that stopped the run.
   * @param string $run
   *   The run, for the log line.
   * @param string $sessionId
   *   The session, for the log line.
   *
   * @return string
   *   The result of the call.
   */
  private function failed(\Throwable $exception, string $groupId, string $run, string $sessionId): string {
    $schema = $exception instanceof SchemaRetriesExhaustedException;
    // An operator cannot tell a quiet session from a session whose every
    // draft fails, unless both kinds of failure reach the log. The exception
    // travels with the record, which keeps the file, the line and the
    // trace.
    $this->logger->log($schema ? LogLevel::WARNING : LogLevel::ERROR, 'Drafting @group of session @session failed in run @run: @message', [
      '@group' => $groupId === '' ? 'the set' : sprintf('the "%s" group', $groupId),
      '@session' => $sessionId,
      '@run' => $run === '' ? 'none' : $run,
      '@message' => $exception->getMessage(),
      'exception' => $exception,
    ]);

    // A raw message can carry a database host or a gateway response, and
    // this agent repeats to the editor what it reads. A schema miss names
    // the fields of the editor's own template, so the model reads that one.
    // Every other failure becomes one sentence.
    return $this->result = json_encode([
      'error' => $schema ? $exception->getMessage() : 'The group could not be drafted.',
      'group' => $groupId,
    ]);
  }

  /**
   * Runs the drafter for one group and reports the values it wrote.
   *
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
  private function draftGroup(array $group, string $run, string $task): array {
    $state = $this->workflows->createWorkflow('field_group_drafter', [], [
      'session' => $this->getContextValue('session'),
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
   * A run that failed leaves its drafter threads behind, and the next one
   * takes the version it did not store. The random segment is what tells the
   * two apart, and the version is what ties a run to the draft it wrote.
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
   * large as the content type needs. Its name carries the id of the
   * session.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the draft belongs to.
   * @param int $version
   *   The version of the draft.
   * @param string $run
   *   The run that wrote it, which names its drafter conversations.
   * @param array $context
   *   The editorial context snapshot, as draftContext() shapes it.
   * @param array $fields
   *   The field values, keyed by field name.
   */
  private function storeDraft(
    ContentCreationSessionInterface $session,
    int $version,
    string $run,
    array $context,
    array $fields,
  ): void {
    $this->store->append($session->draftsThread(), new AssistantMessage(json_encode([
      'version' => $version,
      'run' => $run,
      'context' => $context,
      'fields' => $fields,
    ])));
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
   * What the editor and the assistant said in this session.
   *
   * @return string
   *   One line per message, oldest first.
   */
  private function conversation(): string {
    $lines = [];
    // The store closes a turn on the answer that ends it, and this call runs
    // before that answer. loadActive() omits an open turn, so it hides the
    // message that asked for the draft. This reads the whole thread instead.
    // The agent passes the thread of its conversation, so nothing here
    // composes a thread id.
    foreach ($this->store->loadAll((string) $this->getContextValue('thread')) as $message) {
      $text = $message->getContent();
      if ($text !== NULL) {
        $lines[] = $message->getRole() . ': ' . $text;
      }
    }

    return implode("\n", $lines);
  }

  /**
   * Builds what this run tells one group's drafter.
   *
   * @param string $conversation
   *   What the editor and the assistant said.
   * @param array $answers
   *   What the groups drafted so far answered, keyed by group id.
   *
   * @return string
   *   The task.
   */
  private function task(string $conversation, array $answers): string {
    $task = "Conversation context:\n" . $conversation . "\n";
    // A later group must fit the title and the summary the main group
    // wrote, so the task carries them.
    if (isset($answers[self::MAIN_GROUP])) {
      $task .= "Main fields already generated:\n"
        . json_encode($answers[self::MAIN_GROUP]) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
