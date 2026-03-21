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
   * @var array<string, array>
   */
  private array $cachedSchema = [];

  /**
   * Constructs a DraftingPromptBuilder.
   *
   * @param \Drupal\oe_ai_assistant\Service\FormSchemaExtractor $formSchemaExtractor
   *   The form schema extractor service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly FormSchemaExtractor $formSchemaExtractor,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds the system prompt with the content type schema appended.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The content type bundle.
   *
   * @return string
   *   The system prompt text.
   */
  public function buildSystemPrompt(string $entityTypeId, string $bundle): string {
    $prompt = <<<'PROMPT'
You are a content drafting assistant for a CMS editorial workflow.

You can have normal conversations with the editor. Answer questions, discuss
ideas, and help them plan their content. Only use the draft_content tool when
the editor explicitly asks you to generate or draft content.

When the editor asks you to draft or generate content:
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

    if (!empty($bundle)) {
      try {
        $schema = $this->getSchema($entityTypeId, $bundle);
        $prompt .= "\n\nContent type schema:\n" . Json::encode($schema);
      }
      catch (\Exception $e) {
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
   * @return \Drupal\ai\OperationType\Chat\Tools\ToolsInput
   *   The tools input containing the draft_content function tool.
   */
  public function buildTools(): ToolsInput {
    // draft_content tool: produces structured field values.
    $draftTool = new ToolsFunctionInput();
    $draftTool->setName('draft_content');
    $draftTool->setDescription(
      'Produce a complete set of field values for the content type. '
      . 'Field names and value shapes must match the content type schema '
      . 'provided in the system prompt. Always return ALL fields, and '
      . 'list which ones you actually created or modified in changed_fields.'
    );

    $fieldsParam = new ToolsPropertyInput();
    $fieldsParam->setName('fields');
    $fieldsParam->setType('object');
    $fieldsParam->setDescription('Complete field values keyed by field machine name.');
    $fieldsParam->setRequired(TRUE);

    // The LLM declares which fields it actually changed so the frontend
    // can progressively stream only those and send the rest in one shot.
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
   * Flattens the grouped schema structure into a lookup keyed by field
   * machine name. Used by the plugin's streaming logic to determine which
   * fields should be streamed word-by-word vs sent whole.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The content type bundle.
   *
   * @return array
   *   Field definitions keyed by machine name, or empty array on failure.
   */
  public function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->getSchema($entityTypeId, $bundle);
      $index = [];
      foreach ($schema['groups'] ?? [] as $group) {
        foreach ($group['fields'] ?? [] as $field) {
          $index[$field['name']] = $field;
        }
      }
      return $index;
    }
    catch (\Exception $e) {
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
   * @param string $entityTypeId
   *   The entity type ID.
   * @param string $bundle
   *   The content type bundle.
   *
   * @return array
   *   The extracted schema array.
   *
   * @throws \Exception
   *   Re-throws any exception from the extractor.
   */
  private function getSchema(string $entityTypeId, string $bundle): array {
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
