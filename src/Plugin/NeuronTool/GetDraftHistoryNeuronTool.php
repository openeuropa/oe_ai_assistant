<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronTool;

use Drupal\ai_neuron\Agent\NeuronAgentManagerInterface;
use Drupal\ai_neuron\Attribute\NeuronTool;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Drafting\DraftHistoryInterface;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;

/**
 * Lists the drafts generated in the session being served.
 *
 * Each entry carries the "Draft M.m" name the editor sees and the
 * provenance snapshot stored at generation time. The session comes from the
 * turn, so the model cannot read another session's history.
 */
#[NeuronTool(
  id: 'get_draft_history',
  description: 'Returns the drafts generated in this session, one entry per version'
  . ' ("Draft 1.0", "Draft 2.0", a revision "Draft 1.1"), each with the'
  . ' tone, template and documents that produced it. Refer to drafts by'
  . ' these names; the user sees the same names.',
  label: new TranslatableMarkup('Get draft history'),
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetDraftHistoryNeuronTool extends DraftingToolBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DraftingBriefInterface $brief,
    NeuronAgentManagerInterface $agents,
    private readonly DraftHistoryInterface $draftHistory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $brief, $agents);
  }

  /**
   * Returns the drafts of the session as JSON.
   */
  public function __invoke(): string {
    return json_encode(['drafts' => $this->draftHistory->listDrafts($this->session())]);
  }

}
