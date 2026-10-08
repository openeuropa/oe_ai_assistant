<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\DraftSaverInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

/**
 * Writes one stored draft to the node as an unpublished revision.
 *
 * The call is approval gated, so the editor confirms every save whatever asked
 * for it: a request typed in the chat, or the Save button, which sends the
 * same request as a message. The result names the version and the node, so the
 * conversation records which draft produced which node.
 */
#[NeuronTool(
  id: 'save_draft',
  description: 'Writes a stored draft to the content item as an unpublished'
  . ' revision. Name the draft by its version number; get_draft_history lists'
  . ' the drafts and their names. The editor confirms the save before it'
  . ' happens, so report what the result says rather than promising a save.',
  label: new TranslatableMarkup('Save draft'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class SaveDraftNeuronTool extends DraftingToolBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DraftingBriefInterface $brief,
    NeuronAgentManagerInterface $agents,
    private readonly DraftHistoryInterface $draftHistory,
    private readonly DraftSaverInterface $draftSaver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $brief, $agents);
  }

  /**
   * {@inheritdoc}
   *
   * A save writes to the content item, which is the editor's to authorise and
   * not the model's. The policy is set on the Neuron tool rather than declared
   * here, because the plugin is not the tool Neuron asks: ai_neuron wraps it in
   * a proxy, and the gate belongs on the proxy. The string the policy returns
   * is the reason the approver reads.
   */
  public function getNeuron(): ToolInterface {
    return parent::getNeuron()->withApprovalPolicy(
      static fn (): string => 'Saving writes an unpublished revision of the content item.',
    );
  }

  /**
   * Saves the named draft and reports the node it wrote.
   */
  public function __invoke(int $version): string {
    $session = $this->session();
    $draft = $this->draftHistory->getDraftContent($session, $version);
    if ($draft === NULL || $draft['fields'] === []) {
      return json_encode([
        'error' => sprintf('Draft version %d does not exist in this session.', $version),
      ]);
    }

    $saved = $this->draftSaver->save($session, $draft['fields'], $draft['templateId'], $draft['name']);

    return json_encode([
      'version' => $version,
      'name' => $draft['name'],
    ] + $saved);
  }

  /**
   * {@inheritdoc}
   */
  protected function properties(): array {
    return [
      new ToolProperty(
        'version',
        PropertyType::INTEGER,
        'The version number of the draft to save.',
        TRUE,
      ),
    ];
  }

}
