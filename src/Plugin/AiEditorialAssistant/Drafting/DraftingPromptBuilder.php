<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInput;
use Drupal\Component\Serialization\Json;
use Drupal\oe_ai_assistant\Service\FormSchemaExtractor;
use Psr\Log\LoggerInterface;

/**
 * Builds the system prompt, tool definitions, and field index for drafting.
 *
 * Encapsulates all LLM-facing prompt and tool construction for the drafting
 * plugin. Schema extraction results are cached per entity type / bundle pair
 * so that extract() is called at most once per request.
 *
 * This class is not a Drupal service; it is instantiated directly by
 * DraftingPlugin.
 */
class DraftingPromptBuilder {

  /**
   * Per-request schema cache keyed by "entityTypeId:bundle".
   *
   * FormSchemaExtractor::extract() triggers form building which can be
   * expensive (it may bootstrap form elements, invoke hooks, etc.). This
   * cache ensures that both buildSystemPrompt() and buildFieldIndex() share
   * a single extraction call per entity type + bundle combination within
   * the same HTTP request.
   *
   * @var array<string, array>
   */
  private array $cachedSchema = [];

  /**
   * Constructs a DraftingPromptBuilder.
   *
   * @param \Drupal\oe_ai_assistant\Service\FormSchemaExtractor $formSchemaExtractor
   *   The form schema extractor service. Derives a machine-readable schema
   *   from the Drupal entity form, describing each field's name, type,
   *   widget, and constraints.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for oe_ai_assistant. Used to emit warnings when
   *   schema extraction fails so the plugin can still function (with a
   *   schema-free prompt) rather than throwing an unhandled exception.
   */
  public function __construct(
    private readonly FormSchemaExtractor $formSchemaExtractor,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds the system prompt with the content type schema appended.
   *
   * The base prompt instructs the model on its editorial assistant role,
   * the draft_content tool usage rules, and field constraints (e.g. no
   * entity reference fields). If a bundle is supplied and schema extraction
   * succeeds, the full JSON schema is appended so the model knows which
   * field machine names and value shapes to produce.
   *
   * Schema extraction failures are logged as warnings and do not abort
   * the prompt build; the model receives a schema-free prompt instead.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node", "media").
   * @param string $bundle
   *   The content type bundle machine name (e.g. "article", "page"). If
   *   empty, the schema section is omitted from the prompt.
   *
   * @return string
   *   The complete system prompt text, in ASCII-safe plain text.
   */
  public function buildSystemPrompt(string $entityTypeId, string $bundle): string {
    // The heredoc uses single-quoted delimiter (PROMPT) so that no PHP
    // variable interpolation occurs inside the prompt text.
    $prompt = <<<'PROMPT'
You are a content drafting assistant for a CMS editorial workflow.

You can have normal conversations with the editor. Answer questions, discuss
ideas, and help them plan their content. Only use the draft_content tool when
the editor explicitly asks you to generate or draft content.

When the editor asks you to draft or generate content:
- ALWAYS write a brief text message BEFORE calling the draft_content tool.
  Start the message with the marker [TOOL:draft_content] on its own line,
  then write a short sentence for the editor.
  Example:
    [TOOL:draft_content]
    I'll draft that article for you now.
- Use the draft_content tool to return structured field values matching the
  content type schema provided below.
- Always return the COMPLETE set of fields in every tool call, not just the
  ones you changed.
- In the changed_fields parameter, list ONLY the field machine names you
  actually created or modified. On a first draft, list all fields. When
  the editor asks to regenerate or update specific fields, list only those.
  This controls which fields are progressively streamed to the editor.
- When the editor asks to regenerate specific fields, figure out which
  field machine names they refer to, update those, and keep all other
  fields unchanged.
- Do NOT produce values for entity reference fields (media, taxonomies, etc.).
  These are handled separately by the editor.
- For formatted text fields, produce clean HTML appropriate for the field.
- Match the language and tone the editor requests.

When the editor asks a question, makes a comment, or has a conversation that
does not involve generating content, respond normally in plain text. Do NOT
call the draft_content tool for conversational responses.
PROMPT;

    // Append the JSON schema only when a bundle is known. Without a bundle
    // we cannot extract the schema, so the model operates without it.
    if (!empty($bundle)) {
      try {
        $schema = $this->getSchema($entityTypeId, $bundle);
        $prompt .= "\n\nContent type schema:\n" . Json::encode($schema);
      }
      catch (\Exception $e) {
        // Log and continue without the schema rather than surfacing a 500.
        // The model can still draft content; it just won't have field hints.
        $this->logger->warning('Could not load schema for @type/@bundle: @error', [
          '@type' => $entityTypeId,
          '@bundle' => $bundle,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $prompt;
  }

  /**
   * Builds tool definitions for the LLM.
   *
   * Defines the draft_content function tool that the model may call to
   * return structured field values. The tool has two required parameters:
   *   - "fields": an object of field machine name => value pairs.
   *   - "changed_fields": an array of field names the model actually changed.
   *
   * The drupal/ai ToolsInput / ToolsFunctionInput / ToolsPropertyInput
   * classes serialise into the provider-specific format (OpenAI functions,
   * Mistral tools, etc.) before the request is sent.
   *
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tools input containing the draft_content function tool.
   */
  public function buildTools(): ToolsInput {
    // Define the draft_content function tool. This is the only tool the
    // drafting assistant can call; all other model responses are plain text.
    $draftTool = new ToolsFunctionInput();
    $draftTool->setName('draft_content');
    $draftTool->setDescription(
      'Produce a complete set of field values for the content type. '
      . 'Field names and value shapes must match the content type schema '
      . 'provided in the system prompt. Always return ALL fields, and '
      . 'list which ones you actually created or modified in changed_fields.'
    );

    // "fields" parameter: an object whose keys are field machine names and
    // whose values are the drafted content. The exact shape of each value
    // (string, array, nested object) depends on the field type described
    // in the schema appended to the system prompt.
    $fieldsParam = new ToolsPropertyInput();
    $fieldsParam->setName('fields');
    $fieldsParam->setType('object');
    $fieldsParam->setDescription('Complete field values keyed by field machine name.');
    $fieldsParam->setRequired(TRUE);

    // "changed_fields" parameter: the model declares which fields it actually
    // created or modified. The plugin uses this list to decide which fields
    // to stream progressively vs send in one snapshot. On first draft this
    // should be all fields; on regeneration only the touched ones.
    $changedParam = new ToolsPropertyInput();
    $changedParam->setName('changed_fields');
    $changedParam->setType('array');
    $changedParam->setDescription(
      'List of field machine names that were created or modified. '
      . 'On first draft this is all fields. On regeneration this is '
      . 'only the fields the editor asked to change.'
    );
    $changedParam->setRequired(TRUE);

    $draftTool->setProperties([$fieldsParam, $changedParam]);

    return new ToolsInput([$draftTool]);
  }

  /**
   * Builds a flat field index from the content type schema.
   *
   * Flattens the grouped schema structure (schema.groups[].fields[]) into a
   * single lookup array keyed by field machine name. This index is consumed
   * by the field streaming logic to check whether a given
   * field should be streamed incrementally or sent as a single snapshot.
   *
   * Returns an empty array silently on failure because the streamer falls
   * back to whole-field mode for any field not found in the index.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g. "node").
   * @param string $bundle
   *   The content type bundle machine name (e.g. "article").
   *
   * @return array<string, array>
   *   Field definitions keyed by machine name (e.g. ["body" => [...]]),
   *   or an empty array if the bundle is empty or extraction fails.
   */
  public function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->getSchema($entityTypeId, $bundle);
      $index = [];

      // The schema groups fields by form group (e.g. "Basic", "Advanced").
      // Flatten all groups into a single machine-name => field-definition map.
      foreach ($schema['groups'] ?? [] as $group) {
        foreach ($group['fields'] ?? [] as $field) {
          $index[$field['name']] = $field;
        }
      }
      return $index;
    }
    catch (\Exception $e) {
      // Return empty index; the field streamer will send unknown fields
      // as whole-field snapshots rather than streaming them.
      return [];
    }
  }

  /**
   * Returns the extracted schema, using a per-request cache.
   *
   * Calling formSchemaExtractor->extract() triggers form building which can
   * be expensive. Cache the result keyed by entity type and bundle so both
   * buildSystemPrompt() and buildFieldIndex() share a single extraction.
   *
   * The cache lives only for the lifetime of this object (i.e. one HTTP
   * request). It is not a persistent cache and does not need invalidation.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The content type bundle machine name.
   *
   * @return array<string, mixed>
   *   The extracted schema array as returned by FormSchemaExtractor::extract().
   *
   * @throws \Exception
   *   Re-throws any exception from the extractor so callers can decide
   *   whether to log and continue or propagate the failure.
   */
  private function getSchema(string $entityTypeId, string $bundle): array {
    // The cache key combines entity type and bundle to avoid collisions
    // if the builder is ever called with multiple bundle types.
    $cacheKey = "$entityTypeId:$bundle";
    if (!isset($this->cachedSchema[$cacheKey])) {
      $this->cachedSchema[$cacheKey] = $this->formSchemaExtractor->extract(
        $entityTypeId,
        $bundle,
      );
    }
    return $this->cachedSchema[$cacheKey];
  }

}
