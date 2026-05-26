<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;

/**
 * FunctionCall plugin for generating structured content drafts.
 *
 * The LLM calls this tool to return field values matching the
 * content type schema. The plugin itself is a no-op: the
 * DraftingPlugin intercepts the tool call result and emits
 * data-drafted-fields SSE events with the field data.
 */
#[FunctionCall(
  id: 'oe_ai_assistant:draft_content',
  function_name: 'draft_content',
  name: 'Draft Content',
  description: 'Generate or update field values for a content draft. Call this whenever the user asks to draft, create, update, or modify content fields.',
  context_definitions: [
    'fields' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup("Fields"),
      description: new TranslatableMarkup("An object mapping field machine names to their values. Use the field names from the content type schema."),
      required: TRUE,
    ),
    'changed_fields' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup("Changed fields"),
      description: new TranslatableMarkup("List of field machine names that were created or modified in this call."),
      required: FALSE,
    ),
  ],
)]
class DraftContent extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   *
   * No-op. The DraftingPlugin handles the tool call result directly
   * after streaming, rather than relying on plugin execution.
   */
  public function execute() {
    // Intentionally empty. The DraftingPlugin intercepts the tool
    // call arguments from the LLM response and processes them
    // directly (field filtering, data-drafted-fields emission).
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return '';
  }

}
