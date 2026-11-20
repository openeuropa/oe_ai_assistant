<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Service\Drafting;

use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\DraftNode;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes\ValidateNode;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;

/**
 * Drafts the fields of one group of a draft.
 */
final class DraftGroupRunner {

  public function __construct(
    private readonly NeuronWorkflowManagerInterface $workflows,
  ) {}

  /**
   * Drafts a group.
   *
   * @param \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface $session
   *   The session the draft belongs to.
   * @param string $groupId
   *   The id of the group.
   * @param array $schema
   *   The schema of the group.
   * @param string $task
   *   What the editor asked for.
   * @param string $run
   *   The run that names the drafter conversations.
   *
   * @return \Generator
   *   Yields how far the drafter has written, as ['attempt' => int, 'chars' =>
   *   int]. Returns the field values it wrote.
   *
   * @throws \Drupal\oe_ai_assistant\Exception\SchemaRetriesExhaustedException
   *   When no answer of the drafter matched the group schema.
   */
  public function run(ContentCreationSessionInterface $session, string $groupId, array $schema, string $task, string $run): \Generator {
    $events = $this->workflows->createWorkflow('draft_group', [], [
      'session' => $session,
      'group' => $groupId,
      'schema' => $schema,
      'task' => $task,
      'run' => $run,
    ])->events();

    foreach ($events as $event) {
      // Only the progress of the drafter is passed on.
      if ($event instanceof CustomStreamEvent && $event->name === DraftNode::PROGRESS_EVENT && is_array($event->value)) {
        yield [
          'attempt' => (int) ($event->value['attempt'] ?? 1),
          'chars' => (int) ($event->value['chars'] ?? 0),
        ];
      }
    }

    return (array) $events->getReturn()->get(ValidateNode::FIELDS_KEY, []);
  }

}
