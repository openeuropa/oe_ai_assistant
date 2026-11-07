<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronWorkflow;
use Drupal\ai_neuron\NeuronStreamAdapter;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowPluginBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\Messages\Stream\Adapters\PatchedVercelAIAdapter;
use Drupal\oe_ai_assistant\Neuron\Workflow\Events\ChatTurnEvent;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\ChatNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\ChatResumeNode;
use Drupal\oe_ai_assistant\Neuron\Workflow\Nodes\DraftingNode;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Serves one turn of an editorial session.
 *
 * The turn is a cycle: the agent answers the editor or asks for a draft, the
 * run writes the draft, and the agent reads it and answers. A node ends when
 * it returns an event, so the three steps are three nodes and the run records
 * each one. A failure then repeats one step rather than the whole turn.
 */
#[NeuronWorkflow(
  id: 'editorial_turn',
  label: new TranslatableMarkup('Editorial turn'),
  description: new TranslatableMarkup('Answers the editor and writes the draft the answer asks for.'),
  stream_adapter: NeuronStreamAdapter::Custom,
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class EditorialTurnWorkflow extends NeuronWorkflowPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronAgentManagerInterface $agents,
    private readonly NeuronWorkflowManagerInterface $workflows,
    private readonly MessageStoreInterface $store,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  protected function nodes(): array {
    $session = $this->getContextValue('session');
    assert($session instanceof ContentCreationSessionInterface);

    // One agent for the turn: building it here resolves the session's
    // template and the provider while the endpoint can still answer 400, and
    // the node that resumes the turn needs the instance that suspended.
    $agent = $this->agents->createAgent('drafting', ['session' => $session]);

    return [
      new ChatNode($agent),
      new DraftingNode($this->workflows, $this->store, $session, $this->logger),
      new ChatResumeNode($agent),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * A caller hands the editor's message over on the start event, so this is
   * only the fallback for a run nobody gave one to.
   */
  protected function startEvent(): Event {
    return new ChatTurnEvent('', 0);
  }

  /**
   * {@inheritdoc}
   *
   * The nodes yield the chunks of their agents and the progress of the
   * drafting into one stream, so one adapter here shapes both. This is also
   * why the agents declare none.
   */
  protected function customStreamAdapter(string $id): StreamAdapterInterface {
    return new PatchedVercelAIAdapter();
  }

  /**
   * {@inheritdoc}
   *
   * One session has one conversation, and a turn of it is a run of its own.
   */
  protected function runKey(): string {
    return 'session_' . $this->getContextValue('session')->id() . '.' . uniqid();
  }

}
