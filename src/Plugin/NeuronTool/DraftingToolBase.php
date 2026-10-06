<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Tools\NeuronToolPluginBase;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Agent\Nodes\SchemaOutputNode;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;

/**
 * Base class for the tools of one drafting run.
 *
 * The session is the only thing a caller supplies, and everything else a tool
 * reads follows from it. The context is required, so a tool built without a
 * session is refused rather than failing part way through a call.
 */
abstract class DraftingToolBase extends NeuronToolPluginBase {

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface $brief
   *   Reads what the session drafts with.
   * @param \Drupal\ai_neuron\Agent\NeuronAgentManagerInterface $agents
   *   Builds the drafter a group is written by.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly DraftingBriefInterface $brief,
    private readonly NeuronAgentManagerInterface $agents,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Writes one group and returns its field values.
   *
   * A drafting tool and a revising tool both ask for the same run, so what that
   * run is asked for lives here rather than in each.
   *
   * @param string $group
   *   The group to write, which names the run's conversation.
   * @param array $schema
   *   The JSON schema the answer is validated against.
   * @param string $task
   *   What the drafter is asked to write.
   *
   * @return array
   *   The field values, empty when the run produced none.
   *
   * @throws \Throwable
   *   When the provider call fails, or no answer matches the schema.
   */
  protected function draftGroup(string $group, array $schema, string $task): array {
    return $this->agents->createAgent('field_group', [
      'session' => $this->session(),
      'group' => $group,
      'schema' => $schema,
      'task' => $task,
    ])->run()->get(SchemaOutputNode::OUTPUT_KEY) ?? [];
  }

  /**
   * The session the run belongs to.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session the agent was built for.
   */
  protected function session(): AiEditorialSessionInterface {
    $session = $this->getContextValue('session');
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * The entity type and bundle the run drafts for.
   *
   * @return array
   *   The entity type id and the bundle, in that order.
   */
  protected function target(): array {
    return [
      DraftingBriefInterface::ENTITY_TYPE_ID,
      $this->session()->getContentType(),
    ];
  }

  /**
   * What the editor set up, as a draft of this run records it.
   *
   * @return array
   *   The snapshot, as snapshot() shapes it.
   */
  protected function draftContext(): array {
    $session = $this->session();

    return self::snapshot(
      $this->brief->tone($session),
      $this->brief->template($session),
      $this->brief->documents($session),
      $this->brief->groups($session),
    );
  }

  /**
   * Shapes what the editor set up into the snapshot stored on a draft.
   *
   * Ids travel with the labels read at request time, so a draft keeps what the
   * editor saw even if a term or a template is renamed later. The groups travel
   * with it as well, so a draft can be revised against the structure it was
   * written with. The file name and the extracted text stay out: a draft
   * records which documents were used, not their contents.
   *
   * @param array|null $tone
   *   The tone the editor selected, or NULL when none is.
   * @param array|null $template
   *   The template the editor selected, or NULL when none is.
   * @param array $documents
   *   The documents the editor attached.
   * @param array $groups
   *   The groups the draft is written against.
   *
   * @return array
   *   The snapshot.
   */
  public static function snapshot(?array $tone, ?array $template, array $documents, array $groups): array {
    return [
      'tone' => $tone,
      'template' => $template,
      'documents' => array_map(static function (array $document): array {
        unset($document['filename'], $document['extract']);
        return $document;
      }, $documents),
      'groups' => $groups,
    ];
  }

}
