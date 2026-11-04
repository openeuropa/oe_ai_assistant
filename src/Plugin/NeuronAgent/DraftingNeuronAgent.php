<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\NeuronStreamAdapter;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;

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
    'drafting_get_history',
    'drafting_draft_content',
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
    - Do NOT call drafting_draft_content until you have addressed every
      field group. Ask the user about each group you are unsure about.
    - The user may tell you to skip certain fields, use your best
      judgment, or just go ahead. In that case, proceed with
      drafting_draft_content using whatever context you have.
    - Only call drafting_draft_content when either:
      (a) you have gathered specific information for all field
          groups, or
      (b) the user has explicitly told you to proceed without it.
    - Call drafting_draft_content once. It writes every field group and
      answers with the name of the draft it stored.
    - Tell the user which draft is ready and that they can review it on
      the right. Do not repeat the field values.
    - Only report a draft as ready when the result carries a draft. When
      the result carries an error, say which group failed and what it
      reported, and do not claim a draft is ready.
    - The field groups below are the ones a new draft follows.
    - Answer questions about earlier drafts with drafting_get_history.
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
    $bundle = $this->getContextValue('session')->getContentType();
    $prompt = "Content type context:\n"
      . 'bundle: ' . $bundle . "\n"
      . 'entity_type_id: ' . ContentCreationSessionInterface::ENTITY_TYPE_ID . "\n";

    if ($bundle !== '') {
      // A group definition carries the schema of the group, which is large
      // and grows with the content type. This agent only asks the editor
      // about a group, and each drafter reads its own schema from the
      // provider. So the prompt names the groups and stops there.
      $prompt .= "\nAvailable field groups:\n";
      foreach ($this->getContextValue('session')->getFieldGroups() as $group) {
        $prompt .= sprintf("%s: %s\n", $group['groupId'], $group['label']);
      }
    }

    return $prompt;
  }

}
