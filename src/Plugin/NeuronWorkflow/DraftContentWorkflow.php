<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronWorkflow;

use Drupal\ai_neuron\Attribute\NeuronWorkflow;
use Drupal\ai_neuron\Workflow\NeuronWorkflowManagerInterface;
use Drupal\ai_neuron\Workflow\NeuronWorkflowPluginBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;
use Drupal\oe_ai_assistant\Neuron\Chat\History\EditorialMessageStore;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Events\DraftContentRequestEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\Nodes\DraftContentNode;
use NeuronAI\Workflow\Events\Event;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes one draft of a session, and stores it under the next version.
 *
 * WorkflowToolNode runs this instead of executing drafting_draft_content.
 * Each field group is drafted by the draft_group workflow, and the editor
 * watches the groups fill in as this run reports them.
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
    return [new DraftContentNode((string) $this->getContextValue('call'))];
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
    );
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
