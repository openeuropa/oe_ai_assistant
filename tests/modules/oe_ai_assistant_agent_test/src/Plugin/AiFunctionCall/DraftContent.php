<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant_agent_test\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;

/**
 * FunctionCall plugin for triggering content drafting.
 *
 * The LLM calls this tool when the user asks to generate content.
 * The plugin itself does not execute the drafting the AgentTestPlugin
 * intercepts the tool call and orchestrates sub-agents instead.
 */
#[FunctionCall(
  id: 'oe_ai_assistant_agent_test:draft_content',
  function_name: 'draft_content',
  name: 'Draft Content',
  description: 'Generate draft content based on the conversation context. Call this when the user asks to draft, generate, or write content.',
  context_definitions: [
    'instructions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Instructions"),
      description: new TranslatableMarkup("Summary of what the user wants generated, including topic, tone, and any specific requirements mentioned in the conversation."),
      required: TRUE,
    ),
  ],
)]
class DraftContent extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   *
   * Not called directly -- the plugin orchestrates sub-agents instead.
   */
  public function execute() {
    // Intentionally empty. The AgentTestPlugin intercepts the tool call
    // before execution and runs the orchestration loop.
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return '';
  }

}
