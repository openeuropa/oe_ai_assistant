<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronStreamAdapter;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Service\Drafting\DraftingBriefInterface;

/**
 * The agent that talks to the editor and drafts through tools.
 */
#[NeuronAgent(
  id: DraftingNeuronAgent::PLUGIN_ID,
  label: new TranslatableMarkup('Drafting'),
  description: new TranslatableMarkup('Gathers requirements in conversation and drafts content per field group.'),
  operation_type: 'chat_with_tools',
  stream_adapter: NeuronStreamAdapter::Custom,
  tools: [
    'get_draft_history',
    'draft_group',
  ],
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class DraftingNeuronAgent extends EditorialSessionAgentBase {

  /**
   * The id this agent is registered under.
   */
  public const string PLUGIN_ID = 'drafting';

  /**
   * {@inheritdoc}
   */
  protected function instructions(): string {
    // The tools describe themselves; this is the policy for using them.
    $instructions = <<<'PROMPT'
    You are a content drafting assistant for a CMS editorial workflow.

    Workflow:
    - When the user asks to draft content, review the field groups
      provided below.
    - For each group, determine whether you have enough information
      from the conversation to generate meaningful content. If ANY
      field group lacks context, ask the user about it specifically.
    - Do NOT call draft_group until you have addressed every field
      group. Ask the user about each group you are unsure about.
    - The user may tell you to skip certain fields, use your best
      judgment, or just go ahead. In that case, proceed with
      draft_group using whatever context you have.
    - Only call draft_group when either:
      (a) you have gathered specific information for all field
          groups, or
      (b) the user has explicitly told you to proceed without it.
    - Call draft_group once for every group, all in the same turn,
      starting with main_fields. If a result still lists pending
      groups, call draft_group for each of them before answering.
    - Once the draft is versioned, tell the user which draft is ready
      and that they can review it on the right. Do not repeat the
      field values.
    - The field groups below are the ones a new draft follows.
    - Answer questions about earlier drafts with get_draft_history.
    - You can have normal conversations with the user at any point.
    PROMPT;

    return $instructions . "\n\n" . $this->contentTypePrompt() . "\n";
  }

  /**
   * Renders what the agent is told about the content type it drafts.
   *
   * @return string
   *   The prompt.
   */
  private function contentTypePrompt(): string {
    $bundle = $this->session()->getContentType();
    $prompt = "Content type context:\n"
      . 'bundle: ' . $bundle . "\n"
      . 'entity_type_id: ' . DraftingBriefInterface::ENTITY_TYPE_ID . "\n";

    if ($bundle !== '') {
      $prompt .= "\nAvailable field groups:\n" . json_encode($this->brief->groups($this->session())) . "\n";
    }

    return $prompt;
  }

}
