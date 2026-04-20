<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\AiEditorialAssistant\Drafting;

use Drupal\Component\Serialization\Json;
use Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer;
use Drupal\oe_ai_assistant\Tool\DraftContentTool;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Builds the system prompt, tool definitions, and field index for drafting.
 *
 * Schema is sourced from EntityJsonSchemaComposer, which returns a JSON
 * Schema document with a flat `properties` map keyed by field machine
 * name. See EntityJsonSchemaComposer for the schema shape and supported
 * field types.
 *
 * This class is not a Drupal service; it is instantiated directly by
 * DraftingPlugin.
 */
class DraftingPromptBuilder {

  /**
   * Per-request schema cache keyed by "entityTypeId:bundle".
   *
   * Composing a bundle schema walks every field's typed-data tree and
   * recurses into paragraph reference targets - non-trivial work that
   * we should not redo when buildSystemPrompt() and buildFieldIndex()
   * are both invoked for the same entity type + bundle pair within a
   * single HTTP request.
   *
   * @var array<string, array>
   */
  private array $cachedSchema = [];

  /**
   * Constructs a DraftingPromptBuilder.
   *
   * @param \Drupal\oe_ai_assistant\Service\EntityJsonSchemaComposer $composer
   *   The JSON Schema composer service. Produces a real JSON Schema
   *   document for a content-entity bundle, with a flat `properties`
   *   map at the top level.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel for oe_ai_assistant. Used to emit warnings when
   *   schema composition fails so the plugin can still function (with a
   *   schema-free prompt) rather than throwing an unhandled exception.
   */
  public function __construct(
    private readonly EntityJsonSchemaComposer $composer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds the system prompt with the content type schema appended.
   *
   * The base prompt instructs the model on its editorial assistant role,
   * the draft_content tool usage rules, and field constraints (e.g. no
   * entity reference fields). If a bundle is supplied and schema composition
   * succeeds, the full JSON schema is appended so the model knows which
   * field machine names and value shapes to produce.
   *
   * Schema composition failures are logged as warnings and do not abort
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
    // we cannot compose the schema, so the model operates without it.
    if (!empty($bundle)) {
      try {
        $schema = $this->getSchema($entityTypeId, $bundle);
        $prompt .= "\n\nContent type schema:\n" . Json::encode($schema);
      }
      catch (\Exception $e) {
        // Log and continue without the schema rather than surfacing a 500.
        // The composer can throw \InvalidArgumentException for non-content
        // entity types; the model can still draft content without hints.
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
   * Builds the Symfony AI Tool metadata for the draft_content tool.
   *
   * Defines the draft_content function tool that the model may call to
   * return structured field values. The tool has two parameters:
   *   - "fields": an object of field machine name => value pairs.
   *   - "changed_fields": an array of field names the model actually changed.
   *
   * The Tool metadata provides a custom JSON schema that bypasses
   * ReflectionToolFactory, allowing dynamic field structures.
   *
   * @return \Symfony\AI\Platform\Tool\Tool
   *   The Tool metadata for the draft_content function.
   */
  public function buildToolMetadata(): Tool {
    return new Tool(
      new ExecutionReference(DraftContentTool::class),
      'draft_content',
      'Generate or update field values for a content draft. '
        . 'Call this whenever the user asks to draft, create, '
        . 'update, or modify content fields.',
      [
        'type' => 'object',
        'properties' => [
          'fields' => [
            'type' => 'object',
            'description' => 'An object mapping field machine names '
            . 'to their values. Use the field names from the '
            . 'content type schema.',
            'additionalProperties' => TRUE,
          ],
          'changed_fields' => [
            'type' => 'array',
            'description' => 'List of field machine names that '
            . 'were created or modified in this call.',
            'items' => ['type' => 'string'],
          ],
        ],
        'required' => ['fields'],
      ],
    );
  }

  /**
   * Builds a flat field index from the content type schema.
   *
   * The composer emits a flat `properties` map keyed by field machine name,
   * so the field index is just that map - no group/tab flattening is needed
   * any more. This index is consumed by the field streaming logic to check
   * whether a given field should be streamed incrementally or sent as a
   * single snapshot.
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
   *   Field schemas keyed by machine name (e.g. ["body" => [...]]),
   *   or an empty array if the bundle is empty or composition fails.
   */
  public function buildFieldIndex(string $entityTypeId, string $bundle): array {
    if (empty($bundle)) {
      return [];
    }
    try {
      $schema = $this->getSchema($entityTypeId, $bundle);
      return $schema['properties'] ?? [];
    }
    catch (\Exception) {
      // Return empty index; the field streamer will send unknown fields
      // as whole-field snapshots rather than streaming them.
      return [];
    }
  }

  /**
   * Returns the composed schema, using a per-request cache.
   *
   * Composing a bundle schema is non-trivial (typed-data walk + paragraph
   * recursion); cache the result keyed by entity type and bundle so both
   * buildSystemPrompt() and buildFieldIndex() share a single composition.
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
   *   The composed schema array as returned by
   *   EntityJsonSchemaComposer::compose().
   *
   * @throws \Exception
   *   Re-throws any exception from the composer so callers can decide
   *   whether to log and continue or propagate the failure.
   */
  private function getSchema(string $entityTypeId, string $bundle): array {
    // The cache key combines entity type and bundle to avoid collisions
    // if the builder is ever called with multiple bundle types.
    $cacheKey = "$entityTypeId:$bundle";
    if (!isset($this->cachedSchema[$cacheKey])) {
      $this->cachedSchema[$cacheKey] = $this->composer->compose(
        $entityTypeId,
        $bundle,
      );
    }
    return $this->cachedSchema[$cacheKey];
  }

}
