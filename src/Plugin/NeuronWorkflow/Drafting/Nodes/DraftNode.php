<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Nodes;

use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\DraftingResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Events\DraftedEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\Drafting\Events\DraftRequestEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Asks the drafter agent for the values of one field group.
 */
final class DraftNode extends Node {

  /**
   * Builds the node.
   *
   * @param string $group
   *   The field group to draft.
   * @param array $schema
   *   The JSON schema the drafter's provider carries.
   * @param string $run
   *   The run this node drafts for, which names the drafter's conversation.
   */
  public function __construct(
    private readonly string $group,
    private readonly array $schema,
    private readonly string $run,
  ) {}

  /**
   * The stream event that reports how far the drafter has written.
   */
  public const string PROGRESS_EVENT = 'draft-group-progress';

  /**
   * How many characters the drafter writes between two progress events.
   */
  private const int PROGRESS_EVERY = 400;

  /**
   * Runs one turn of the drafter, and reports how far it has written.
   */
  public function __invoke(DraftRequestEvent $event, WorkflowState $state, DraftingResources $resources): \Generator {
    $agent = $resources->agents->createAgent('group_drafter', [
      'session' => $resources->session,
      'group' => $this->group,
      'schema' => $this->schema,
      'run' => $this->run,
    ]);

    // A correction turn is the next attempt at the same group.
    $attempt = (int) $state->get(RetryNode::ATTEMPTS_KEY, 0) + 1;
    $chars = 0;
    $lastEvent = microtime(TRUE);

    // The answer is JSON, which means nothing to the editor while it grows.
    // So the chunks are not passed on. The node reports the first chunk, then
    // one event for each PROGRESS_EVERY characters, and one when a second has
    // passed since the last event and the answer grew. The stream also
    // keeps the call alive: a provider behind a gateway can refuse a call
    // that sends nothing until it finishes.
    $chunks = $agent->stream(new UserMessage($event->message));
    foreach ($chunks as $chunk) {
      if (!$chunk instanceof TextChunk || $chunk->content === '') {
        continue;
      }
      $before = $chars;
      $chars += mb_strlen($chunk->content);
      if ($before === 0 || intdiv($chars, self::PROGRESS_EVERY) > intdiv($before, self::PROGRESS_EVERY) || microtime(TRUE) - $lastEvent >= 1.0) {
        $lastEvent = microtime(TRUE);
        yield new CustomStreamEvent(self::PROGRESS_EVENT, [
          'group' => $this->group,
          'attempt' => $attempt,
          'chars' => $chars,
        ]);
      }
    }

    return new DraftedEvent($chunks->getReturn()->getMessage()?->getContent() ?? '');
  }

}
