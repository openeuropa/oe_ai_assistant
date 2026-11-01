<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\ValidateNode;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
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
final class DraftContentNeuronTool extends DraftingToolBase {

  /**
   * The group that carries the title and the summary of the content.
   */
  private const MAIN_GROUP = 'main_fields';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronWorkflowManagerInterface $workflows,
    private readonly MessageStoreInterface $store,
    private readonly TimeInterface $time,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Drafts the whole set and reports the draft it stored.
   */
  public function __invoke(): string {
    $session = $this->getContextValue('session');
    assert($session instanceof ContentCreationSessionInterface);

    $groups = $session->getFieldGroups();
    $version = (int) $session->nextVersion()['version'];
    $run = $this->runId($version);

    $conversation = $this->conversation();
    $answers = [];
    foreach ($groups as $group) {
      $groupId = (string) $group['groupId'];

      try {
        $answers[$groupId] = $this->draftGroup($group, $run, $this->task($conversation, $answers));
      }
      catch (\Throwable $e) {
        // The set is incomplete, so there is no draft. The model is told
        // which group stopped the run, and says so rather than reporting a
        // draft the editor cannot open.
        return $this->failed($e, $groupId, $run, (string) $session->id());
      }
    }

    $this->storeDraft($session, $version, $run, $this->assemble($groups, $answers));

    // The model is told the name of the draft and reports that, so the field
    // values never reach the conversation it replays each turn.
    return $this->json(['draft' => sprintf('Draft %d', $version), 'version' => $version]);
  }

  /**
   * Reports a group the run could not write.
   *
   * A schema failure says which fields the drafter missed, which the editor
   * can act on. Anything else is the site's business: it goes to the log,
   * and the model is told only that the group failed, so a database error or
   * a gateway response never reaches the editor.
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
    if ($exception instanceof SchemaRetriesExhaustedException) {
      return $this->json(['error' => $exception->getMessage(), 'group' => $groupId]);
    }

    $this->logger->error('Drafting the "@group" group of session @session failed in run @run: @message', [
      '@group' => $groupId,
      '@session' => $sessionId,
      '@run' => $run,
      '@message' => $exception->getMessage(),
    ]);

    return $this->json(['error' => 'The group could not be drafted.', 'group' => $groupId]);
  }

  /**
   * Encodes a result for the model.
   *
   * A model answers text of its own, so a value it wrote can hold a byte
   * sequence JSON cannot carry. Such a byte becomes U+FFFD rather than an
   * encode failure, which would leave the call with nothing to answer.
   *
   * @param array $result
   *   What to tell the model.
   *
   * @return string
   *   The JSON.
   */
  private function json(array $result): string {
    return json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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
   * @throws \RuntimeException
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
   * takes the version it did not store. The time of the run is what tells
   * the two apart. It carries no dot, because a dot separates the segments
   * of the thread names built from it.
   *
   * @param int $version
   *   The version this run drafts.
   *
   * @return string
   *   The run id, which names the drafter conversations of this run.
   */
  private function runId(int $version): string {
    $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->time->getCurrentMicroTime()))
      ->setTimezone(new \DateTimeZone('UTC'));

    return sprintf('draft_%d.%s', $version, $time->format('Ymd\THisv\Z'));
  }

  /**
   * Keeps the draft in a thread of its own.
   *
   * The thread carries the id of the session, and nothing reads it back into
   * a model, so the draft is as large as the content type needs.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the draft belongs to.
   * @param int $version
   *   The version of the draft.
   * @param string $run
   *   The run that wrote it, which names its drafter conversations.
   * @param array $fields
   *   The field values, keyed by field name.
   */
  private function storeDraft(
    ContentCreationSessionInterface $session,
    int $version,
    string $run,
    array $fields,
  ): void {
    $this->store->append($session->draftsThread(), new AssistantMessage($this->json([
      'version' => $version,
      'run' => $run,
      'context' => $this->draftContext(),
      'fields' => $fields,
    ])));
  }

  /**
   * Merges what each group answered into one field map.
   *
   * A group answers for its own fields, and a model does not always key the
   * answer the way the schema asks. The two shapes it returns instead are
   * repaired here: a main group that carries fields of another group, and a
   * reference group that answers a bare list rather than a keyed object.
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
          // A group of one field is the only one whose bare list can be read
          // as that field. Of a group of several, a list says nothing about
          // which field it belongs to, so the group contributes nothing.
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
    // The agent passes the thread it holds the conversation under, so nothing
    // here composes a thread id.
    foreach ($this->store->loadActive((string) $this->getContextValue('thread')) as $message) {
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
    // The main fields are the title and the summary of the content, so every
    // later group goes with them.
    if (isset($answers[self::MAIN_GROUP])) {
      $task .= "Main fields already generated:\n"
        . $this->json($answers[self::MAIN_GROUP]) . "\n\n";
    }

    return $task . 'Generate content for the fields in the provided schema. Follow the conversation context.';
  }

}
