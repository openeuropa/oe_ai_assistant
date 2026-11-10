<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\Bundle\ContentCreationSessionInterface;

/**
 * The agent that talks to the editor and drafts through tools.
 */
#[NeuronAgent(
  id: EditorialNeuronAgent::PLUGIN_ID,
  label: new TranslatableMarkup('Editorial'),
  description: new TranslatableMarkup('Gathers requirements in conversation and drafts content per field group.'),
  operation_type: 'chat_with_tools',
  tools: [
    'drafting_get_history',
    'drafting_draft_content',
    'context_get_editorial_context',
    'context_read_document',
  ],
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class EditorialNeuronAgent extends EditorialSessionAgentBase {

  /**
   * The id this agent is registered under.
   */
  public const string PLUGIN_ID = 'editorial';

  /**
   * {@inheritdoc}
   *
   * An editor attaches a document and then asks this agent about it. No
   * other block of this prompt names the attachment, so the agent has no
   * text to answer from. The editor also hears nothing about a document
   * that is still in extraction, or one whose extraction failed. The
   * documents block carries the text and both warnings. This agent writes
   * no field of the draft, so the prompt omits the tone.
   */
  protected function instructions(): string {
    // The tools describe themselves; this is the policy for using them.
    $instructions = <<<'PROMPT'
    You are a content drafting assistant for a CMS editorial workflow.

    Workflow:
    - When the user asks to draft content, review the field groups
      provided below.
    - The editor may attach context documents. Any they attached are
      listed below, with the text of each one. Read them before you
      answer a question about this session, and again before you call
      drafting_draft_content. Treat what they say as information you
      already hold about the field groups.
    - When the editor refers to a document, answer from its text below.
      Say so when a document is still being processed or when it failed,
      so the editor knows what a draft can use.
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
    - One call of drafting_draft_content writes one draft. It writes
      every field group and answers with the name of the draft it stored.
    - When the user asks for several drafts at once, call it once for
      each draft they asked for, in the same reply.
    - Tell the user the name of every draft that is ready and that they
      can review them on the right. Do not repeat the field values.
    - Only report a draft as ready when the result carries a draft. When
      the result carries an error, say which group failed and what it
      reported, and do not claim a draft is ready.
    - The field groups below are the ones a new draft follows.
    - Answer questions about earlier drafts with drafting_get_history.
    - Answer questions about the session itself, what it is about, what
      material is attached, what a document covers, by calling
      context_get_editorial_context first. Read one document in full with
      context_read_document only when its summary does not answer the question.
      Never guess what a document contains.
    - You can have normal conversations with the user at any point.
    PROMPT;

    $blocks = array_filter([$this->contentTypePrompt(), $this->documentsPrompt()]);

    return $instructions . "\n\n" . implode("\n\n", $blocks) . "\n";
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
