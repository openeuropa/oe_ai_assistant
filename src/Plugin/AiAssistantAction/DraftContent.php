<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiAssistantAction;

use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * AI Assistant Action plugin that handles LLM-generated content field drafts.
 *
 * This plugin is a "tool" in the LLM tool-calling sense: the Drafting plugin
 * instructs the model to call draft_content once it has decided on a complete
 * set of field values for the requested content type. The LLM's tool call
 * arrives as a JSON payload containing a "fields" key whose value is an object
 * mapping field machine names to their values.
 *
 * Responsibilities
 * ----------------
 * 1. Declare the draft_content action via listActions() so the AI assistant
 *    API framework knows which tool calls this plugin handles.
 * 2. Receive the field values in triggerAction() and persist them through
 *    two output channels:
 *      - setOutputContext(): stores a JSON-encoded string in the AG-UI context
 *        so other pipeline stages can read the draft.
 *      - setStructuredResults(): stores the raw array so the streaming loop
 *        can emit a ToolCallResult event back to the frontend.
 * 3. Provide getFunctionCallSchema() so the LLM knows the exact parameter
 *    shape it must produce when calling this tool.
 * 4. Provide provideFewShotLearningExample() to improve model reliability
 *    by showing a concrete before/after example in the system prompt.
 *
 * This plugin has no configuration UI (buildConfigurationForm returns the
 * empty form), no required contexts (listContexts returns an empty array),
 * and no configuration defaults (defaultConfiguration returns an empty array).
 *
 * @see \Drupal\oe_ai_assistant\Plugin\OeAiAssistant\Drafting\DraftingPlugin
 * @see \Drupal\oe_ai_assistant\Service\LlmStreamingLoop
 */
#[AiAssistantAction(
  id: 'oe_ai_draft_content',
  label: new TranslatableMarkup('Draft Content'),
)]
class DraftContent extends AiAssistantActionBase {

  /**
   * {@inheritdoc}
   *
   * This plugin requires no persistent configuration, so the default
   * configuration is an empty array. If future versions need stored settings
   * (e.g., a list of excluded fields), they would be declared here.
   *
   * @return array<string, mixed>
   *   An empty array; no configuration keys are defined.
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Declares the single action this plugin handles: draft_content.
   *
   * The AI assistant API framework uses this map to route incoming LLM tool
   * calls to the correct plugin. When the LLM emits a tool call with
   * action == 'draft_content', the framework invokes triggerAction() on this
   * plugin.
   *
   * Each entry in the returned array is an action definition with the keys:
   *   - id: machine name used in LLM tool call payloads.
   *   - plugin: the plugin ID of this AiAssistantAction plugin.
   *   - label: human-readable label for admin UI display.
   *   - description: short description included in the LLM system prompt.
   *
   * @return array<string, array<string, mixed>>
   *   A map of action ID => action definition array.
   */
  public function listActions(): array {
    return [
      'draft_content' => [
        'id' => 'draft_content',
        'plugin' => 'oe_ai_draft_content',
        'label' => new TranslatableMarkup('Draft content fields'),
        'description' => new TranslatableMarkup(
          'Produce a complete set of field values for a content type.'
        ),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * This plugin does not require any context from the Drupal state or from
   * previous pipeline stages. All input arrives through the LLM tool call
   * parameters in triggerAction().
   *
   * @return array<string, mixed>
   *   An empty array; no context keys are consumed.
   */
  public function listContexts(): array {
    return [];
  }

  /**
   * Handles an incoming LLM tool call for the draft_content action.
   *
   * The AI assistant API framework calls this method when the LLM issues a
   * tool call whose action ID matches one of the entries in listActions().
   * The $parameters argument carries the decoded tool call payload.
   *
   * Parameter normalisation
   * -----------------------
   * Different LLM providers and prompt styles may produce slightly different
   * payload shapes. The fields data may arrive as $parameters['fields']
   * (when the LLM followed the schema exactly) or as $parameters itself
   * (when the LLM flattened the object). Both shapes are handled here.
   *
   * Output channels
   * ---------------
   * Two complementary output channels are populated so that downstream
   * pipeline stages and the SSE streaming loop can each read the draft in
   * the format they prefer:
   *   - setOutputContext('draft_content', json_encode($fields)): stores a
   *     JSON string keyed by 'draft_content'. Other action plugins or
   *     context-aware pipeline stages can retrieve this via the AG-UI
   *     context store.
   *   - setStructuredResults('drafted_fields', $fields): stores the raw PHP
   *     array. The LlmStreamingLoop reads this to emit a ToolCallResult
   *     event back to the frontend after the tool call completes.
   *
   * @param string $action_id
   *   The action ID from the LLM tool call. Must equal 'draft_content' for
   *   this plugin to act; any other value is silently ignored.
   * @param array<string, mixed> $parameters
   *   The decoded parameters from the LLM tool call payload. Expected to
   *   contain a 'fields' key with field machine-name => value pairs, or to
   *   be the fields map itself.
   */
  public function triggerAction(string $action_id, $parameters = []): void {
    // Ignore tool calls intended for other action plugins. The framework may
    // route multiple action IDs through the same pipeline stage.
    if ($action_id !== 'draft_content') {
      return;
    }

    // Normalise the payload: prefer $parameters['fields'] when present,
    // fall back to treating the entire $parameters array as the fields map.
    // This handles both strict and flattened LLM output shapes.
    $fields = $parameters['fields'] ?? $parameters;

    // Persist the draft as a JSON string in the AG-UI context store.
    // Other plugins or pipeline stages can retrieve this with getContext().
    $this->setOutputContext('draft_content', json_encode($fields));

    // Persist the draft as a structured PHP array so the LlmStreamingLoop
    // can emit a ToolCallResult SSE event with the full field data.
    $this->setStructuredResults('drafted_fields', $fields);
  }

  /**
   * Returns few-shot learning examples for inclusion in the LLM system prompt.
   *
   * Few-shot examples teach the model the exact format it must use when
   * calling the draft_content tool. Including a concrete example in the
   * system prompt significantly improves tool-call accuracy, especially for
   * models that are not fine-tuned for function calling.
   *
   * Each example contains:
   *   - description: a plain-text scenario that sets the context.
   *   - schema: the exact tool call payload the model should produce for
   *     that scenario. The 'fields' key contains representative field values
   *     for a typical content type (title, body, teaser).
   *
   * @return array<int, array<string, mixed>>
   *   A list of example definitions. Each entry has a 'description' string
   *   and a 'schema' array representing the expected tool call payload.
   */
  public function provideFewShotLearningExample(): array {
    return [
      [
        'description' => 'When asked to draft a news article about climate policy, call draft_content with all fields:',
        'schema' => [
          'actions' => [
            [
              'action' => 'draft_content',
              'plugin' => 'oe_ai_draft_content',
              // Representative field values matching a typical news content
              // type. The actual field names and value shapes will vary per
              // content type schema provided in the system prompt.
              'fields' => [
                'title' => 'New Climate Policy Framework',
                'body' => [
                  'value' => '<p>The new framework establishes...</p>',
                ],
                'field_teaser' => 'A landmark climate policy framework.',
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Returns the JSON Schema definition for the draft_content tool call.
   *
   * The AI assistant API framework embeds this schema in the system prompt
   * and/or the LLM's tool definitions payload so that the model knows the
   * exact parameter shape it must produce when calling this tool.
   *
   * Schema shape:
   *   - action (string, enum): must always be the literal string
   *     "draft_content". The enum constraint eliminates ambiguity when
   *     multiple tools share a similar structure.
   *   - fields (object): a free-form object whose keys are content field
   *     machine names and whose value shapes match the content type schema
   *     that the Drafting plugin includes in the system prompt. The schema
   *     for fields is intentionally open (no fixed properties) because it
   *     varies per content type at runtime.
   *
   * @return array<string, array<string, mixed>>
   *   A map of parameter name => JSON Schema property definition.
   */
  public function getFunctionCallSchema(): array {
    return [
      'action' => [
        'type' => 'string',
        // The enum constraint tells the model this parameter must always be
        // exactly 'draft_content', preventing it from fabricating action names.
        'description' => 'Must be "draft_content".',
        'enum' => ['draft_content'],
      ],
      'fields' => [
        'type' => 'object',
        // Intentionally open schema: the specific field names and value
        // shapes depend on the content type schema injected by the Drafting
        // plugin at request time. The model is expected to read that schema
        // from the system prompt and populate fields accordingly.
        'description' => 'Complete set of field values for the content type. '
        . 'Field names and value shapes must match the content type '
        . 'schema provided in the system prompt.',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * This plugin has no administrator-facing configuration options, so the
   * form is returned unchanged. Future versions could add checkboxes for
   * excluded fields or output format options.
   *
   * @param array<string, mixed> $form
   *   The form structure array to populate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The unmodified form array.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * No configuration to validate. This method is intentionally left empty.
   *
   * @param array<string, mixed> $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   *
   * No configuration to save. This method is intentionally left empty.
   *
   * @param array<string, mixed> $form
   *   The form structure array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form, which contains submitted values.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {}

}
