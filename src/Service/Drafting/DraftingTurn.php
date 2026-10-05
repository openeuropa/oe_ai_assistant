<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\ThreadAddress;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;

/**
 * What one chat turn knows, for the plugins the turn builds.
 *
 * An agent or a tool is a plugin, so a manager builds it and no caller can
 * hand it the session, the schema groups or the collector of the turn in
 * progress. This service holds them for the length of the request: the chat
 * action opens a turn, and every plugin the run builds reads it from here.
 */
final class DraftingTurn {

  /**
   * How many corrected answers to ask a drafter for before giving up.
   */
  public const MAX_SCHEMA_RETRIES = 5;

  /**
   * The session being served, or NULL outside a chat turn.
   */
  private ?AiEditorialSessionInterface $session = NULL;

  /**
   * What the editor set up for the session.
   */
  private ?EditorialContext $editorialContext = NULL;

  /**
   * The collector of this turn's group results.
   */
  private ?DraftCollector $collector = NULL;

  /**
   * Content type context appended to the conversational agent's prompt.
   */
  private string $agentPrompt = '';

  /**
   * Editorial context appended to a drafter's prompt.
   */
  private string $drafterPrompt = '';

  /**
   * The entity type the session drafts for.
   */
  private string $entityTypeId = '';

  /**
   * The bundle the session drafts for.
   */
  private string $bundle = '';

  /**
   * What tells this chat turn from another of the same session.
   */
  private string $token = '';

  /**
   * The group a drafter is about to be built for.
   *
   * @var array|null
   */
  private ?array $pendingGroup = NULL;

  public function __construct(
    private readonly NeuronWorkflowManagerInterface $drafters,
    private readonly DraftHistoryInterface $draftHistory,
  ) {}

  /**
   * Opens a turn, replacing any turn opened earlier in the request.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface $session
   *   The session being served.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\EditorialContext $editorialContext
   *   Tone, template and documents of the session.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftCollector $collector
   *   The collector of this turn's group results.
   * @param string $agentPrompt
   *   Content type context appended to the conversational agent's prompt.
   * @param string $drafterPrompt
   *   Editorial context appended to a drafter's prompt.
   * @param string $entityTypeId
   *   The entity type the session drafts for.
   * @param string $bundle
   *   The bundle the session drafts for.
   */
  public function open(
    AiEditorialSessionInterface $session,
    EditorialContext $editorialContext,
    DraftCollector $collector,
    string $agentPrompt,
    string $drafterPrompt,
    string $entityTypeId,
    string $bundle,
  ): void {
    $this->session = $session;
    $this->editorialContext = $editorialContext;
    $this->collector = $collector;
    $this->agentPrompt = $agentPrompt;
    $this->drafterPrompt = $drafterPrompt;
    $this->entityTypeId = $entityTypeId;
    $this->bundle = $bundle;
    $this->pendingGroup = NULL;
    // Hex of eight random bytes, which is short enough to read in a thread id
    // and long enough that two turns of one session never collide.
    $this->token = bin2hex(random_bytes(4));
  }

  /**
   * Whether a turn is open, so the session and its context can be read.
   */
  public function isOpen(): bool {
    return $this->session !== NULL;
  }

  /**
   * Returns the session being served.
   *
   * @throws \LogicException
   *   When no turn is open, which means a plugin was built outside a chat
   *   request.
   */
  public function session(): AiEditorialSessionInterface {
    return $this->session ?? throw new \LogicException('No drafting turn is open.');
  }

  /**
   * Returns what the editor set up for the session.
   *
   * @throws \LogicException
   *   When no turn is open.
   */
  public function editorialContext(): EditorialContext {
    return $this->editorialContext ?? throw new \LogicException('No drafting turn is open.');
  }

  /**
   * Returns the collector of this turn's group results.
   *
   * @throws \LogicException
   *   When no turn is open.
   */
  public function collector(): DraftCollector {
    return $this->collector ?? throw new \LogicException('No drafting turn is open.');
  }

  /**
   * Returns the thread id the editorial conversation is held under.
   *
   * @throws \LogicException
   *   When no turn is open.
   */
  public function threadId(): string {
    return 'drafting.' . ThreadAddress::key((string) $this->session()->id());
  }

  /**
   * Returns what tells this chat turn from another of the same session.
   *
   * @throws \LogicException
   *   When no turn is open.
   */
  public function token(): string {
    return $this->token === '' ? throw new \LogicException('No drafting turn is open.') : $this->token;
  }

  /**
   * Returns the content type context for the conversational agent's prompt.
   */
  public function agentPrompt(): string {
    return $this->agentPrompt;
  }

  /**
   * Returns the editorial context for a drafter's prompt.
   */
  public function drafterPrompt(): string {
    return $this->drafterPrompt;
  }

  /**
   * Returns the entity type the session drafts for.
   */
  public function entityTypeId(): string {
    return $this->entityTypeId;
  }

  /**
   * Returns the bundle the session drafts for.
   */
  public function bundle(): string {
    return $this->bundle;
  }

  /**
   * Versions consolidated field values with the context that produced them.
   *
   * A revision inherits the snapshot of the draft it revises, since that
   * context produced the content it starts from.
   *
   * @param array $fields
   *   The consolidated field values.
   * @param int|null $revisionOf
   *   The version being revised, or NULL for a new draft.
   * @param array|null $inherited
   *   The snapshot to inherit, or NULL to snapshot the turn's context.
   *
   * @return array
   *   The draft shaped {version, major, minor, context, fields, revisionOf}.
   */
  public function version(array $fields, ?int $revisionOf = NULL, ?array $inherited = NULL): array {
    return $this->draftHistory->nextVersion($this->session(), $revisionOf) + [
      'context' => $inherited ?? $this->editorialContext()->toSnapshot(),
      'fields' => $fields,
    ];
  }

  /**
   * Returns the group the drafter being built answers for.
   *
   * @return array
   *   {id, schema, task}: the group id, its JSON schema and what the drafter
   *   is asked to write.
   *
   * @throws \LogicException
   *   When a drafter is built outside draft().
   */
  public function pendingGroup(): array {
    return $this->pendingGroup ?? throw new \LogicException('No field group is being drafted.');
  }

  /**
   * Drafts one group through its own agent.
   *
   * @param string $groupId
   *   The group to draft, which names the drafter's conversation.
   * @param array $schema
   *   The JSON schema of the group.
   * @param string $task
   *   What the drafter is asked to write.
   *
   * @return array
   *   The decoded field values.
   *
   * @throws \Throwable
   *   When the provider call fails or no answer matches the schema.
   */
  public function draft(string $groupId, array $schema, string $task): array {
    // The plugin reads the group while the manager builds it, since a
    // manager takes no arguments of its own.
    $this->pendingGroup = [
      'id' => $groupId,
      'schema' => $schema,
      'task' => $task,
    ];
    try {
      return $this->drafters->createWorkflow('field_group')->run()->get(SchemaOutputNode::OUTPUT_KEY) ?? [];
    }
    finally {
      $this->pendingGroup = NULL;
    }
  }

}
