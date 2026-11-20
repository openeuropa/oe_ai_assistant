<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow;

use Drupal\ai_neuron\Attribute\NeuronWorkflow;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowPluginBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Events\DraftContentRequestEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Nodes\DraftContentNode;
use Drupal\oe_ai_assistant\Plugin\QueueWorker\DraftGroupQueueWorker;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupJobs;
use Drupal\oe_ai_assistant\Service\Drafting\DraftGroupRunner;
use NeuronAI\Workflow\Events\Event;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes one draft of a session, and stores it under the next version.
 *
 * The drafter subagent runs this to answer drafting_draft_content. Each field
 * group is drafted by the draft_group workflow, and the editor watches the
 * groups fill in as this run reports them.
 */
#[NeuronWorkflow(
  id: 'draft_content',
  label: new TranslatableMarkup('Content drafter'),
  description: new TranslatableMarkup('Drafts every field group of a session and stores the result as the next draft.'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'call' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Tool call'),
    ),
    'brief' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Editorial brief'),
    ),
    'task' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Subagent task'),
    ),
  ],
)]
final class DraftContentWorkflow extends NeuronWorkflowPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NeuronWorkflowManagerInterface $workflows,
    private readonly EditorialMessageStore $store,
    #[Autowire(service: 'logger.channel.oe_ai_assistant')]
    private readonly LoggerChannelInterface $logger,
    private readonly DraftGroupJobs $jobs,
    private readonly DraftGroupRunner $groupRunner,
    private readonly QueueFactory $queueFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * A plugin is not the node it builds, so what the node needs of the run's
   * context is handed to it here.
   */
  protected function nodes(): array {
    $node = new DraftContentNode(
      (string) $this->getContextValue('call'),
      (string) $this->getContextValue('brief'),
      (string) $this->getContextValue('task'),
    );

    return [$node];
  }

  /**
   * {@inheritdoc}
   */
  protected function resources(): DraftContentResources {
    $session = $this->session();

    return new DraftContentResources(
      $this->workflows,
      // No agent runs on the drafts thread, so the store has no plugin to
      // read the session off. forSession() binds it, so every row it writes
      // records the session.
      $this->store->forSession($session),
      $this->logger,
      $session,
      $this->jobs,
      $this->groupRunner,
      $this->queueFactory->get(DraftGroupQueueWorker::QUEUE),
      $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL,
    );
  }

  /**
   * {@inheritdoc}
   *
   * The run is named after the task it answers, so a later start of the same
   * task finds the run, and the records it keeps, instead of drafting again.
   */
  protected function runKey(): string {
    return (string) $this->getContextValue('task');
  }

  /**
   * {@inheritdoc}
   */
  protected function startEvent(): Event {
    return new DraftContentRequestEvent();
  }

  /**
   * The session this draft belongs to.
   *
   * @return \Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface
   *   The session.
   */
  private function session(): ContentCreationSessionInterface {
    $session = $this->getContextValue('session');
    assert($session instanceof ContentCreationSessionInterface);

    return $session;
  }

}
