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
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\AmendContent\Events\AmendContentRequestEvent;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\AmendContent\Nodes\AmendContentNode;
use Drupal\oe_ai_assistant\Plugin\NeuronWorkflow\DraftContent\DraftContentResources;
use NeuronAI\Workflow\Events\Event;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Amends one stored draft, and stores the result under the next minor version.
 *
 * WorkflowToolNode runs this instead of executing amend_draft. Each named
 * field group is drafted again by the draft_group workflow, and the editor
 * watches the groups fill in as this run reports them.
 */
#[NeuronWorkflow(
  id: 'amend_content',
  label: new TranslatableMarkup('Content amender'),
  description: new TranslatableMarkup('Amends a stored draft and stores the result as its next minor version.'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
    'call' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Tool call'),
    ),
    'version' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source draft version'),
    ),
    'groups' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup('Field group ids to amend'),
    ),
    'brief' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Editorial brief'),
    ),
  ],
)]
final class AmendContentWorkflow extends NeuronWorkflowPluginBase {

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
   */
  protected function nodes(): array {
    $node = new AmendContentNode(
      (string) $this->getContextValue('call'),
      (string) $this->getContextValue('version'),
      (array) $this->getContextValue('groups'),
      (string) $this->getContextValue('brief'),
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
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function startEvent(): Event {
    return new AmendContentRequestEvent();
  }

  /**
   * The session this amendment belongs to.
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
