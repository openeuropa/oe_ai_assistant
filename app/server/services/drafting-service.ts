/**
 * Drafting service: AI-powered content drafting via Mistral.
 *
 * Mirrors DraftingPlugin.php method-for-method. Each method has
 * a JSDoc comment referencing the corresponding PHP method. The
 * service yields AG-UI protocol events as an AsyncGenerator;
 * the route handler writes them to the SSE response.
 */

import type { Mistral } from "@mistralai/mistralai";
import type {
  AssistantMessage,
  ToolMessage,
} from "@mistralai/mistralai/models/components";
import { randomUUID } from "node:crypto";
import { MISTRAL_MODEL } from "../config";
import type {
  ChatMessage,
  ConversationStore,
} from "./conversation-store";

// -- Types -------------------------------------------------------

/** AG-UI event yielded by the service. */
export interface AgUiEvent {
  type: string;
  [key: string]: unknown;
}

/** A single field definition from the content type schema. */
interface SchemaField {
  name: string;
  label: string;
  type: string;
  widget: string;
  interaction: string;
  cardinality: number;
  inlineForm?: {
    targetBundles: Record<
      string,
      { label: string; groups: { fields: SchemaField[] }[] }
    >;
  };
}

/** Content type schema (output of FormSchemaExtractor). */
interface ContentTypeSchema {
  contentType: string;
  label: string;
  groups: { label: string; fields: SchemaField[] }[];
}

/** Flattened field lookup keyed by machine name. */
type FieldIndex = Record<string, SchemaField>;

/** Options for the chat() method. */
export interface ChatOptions {
  message: string;
  threadId?: string;
  entityTypeId: string;
  bundle: string;
  schema: ContentTypeSchema | null;
}

// -- Service class -----------------------------------------------

export class DraftingService {
  /**
   * Maximum iterations for the tool-call loop.
   * Mirrors DraftingPlugin::MAX_ITERATIONS (line 45).
   */
  private static readonly MAX_ITERATIONS = 10;

  constructor(
    private readonly mistral: Mistral,
    private readonly store: ConversationStore,
  ) {}

  // -- Public methods --------------------------------------------

  /**
   * Streams AI chat responses via AG-UI SSE events.
   * Mirrors DraftingPlugin::chat() (lines 128-184) and
   * createStreamResponse() (lines 340-406).
   */
  async *chat(
    opts: ChatOptions,
  ): AsyncGenerator<AgUiEvent> {
    const { message, threadId: inputThreadId, schema } = opts;

    const runId = randomUUID();
    const threadId = inputThreadId || randomUUID();
    const systemPrompt = this.buildSystemPrompt(schema);
    const fieldIndex = this.buildFieldIndex(schema);

    // Parse [fields:name1,name2] tag from the user message.
    // Request-scoped to avoid concurrency issues.
    const fieldsMatch = message.match(/\[fields:([^\]]+)\]/);
    const fieldsToStream = fieldsMatch
      ? fieldsMatch[1]!.split(",")
      : [];

    yield { type: "RUN_STARTED", runId, threadId };

    try {
      // Load conversation history.
      const history = this.store.load(threadId);

      // Add the new user message to history.
      history.push({
        role: "user" as const,
        content: message,
      });

      // Run the LLM with tool loop.
      const gen = this.runLlmLoop(
        systemPrompt,
        history,
        fieldIndex,
        fieldsToStream,
      );
      let result = await gen.next();
      while (!result.done) {
        yield result.value;
        result = await gen.next();
      }
      const assistantText: string = result.value;

      // Save updated history.
      if (assistantText) {
        history.push({
          role: "assistant" as const,
          content: assistantText,
        });
      }
      this.store.save(threadId, history);
    } catch (err) {
      const errorMessage =
        err instanceof Error ? err.message : String(err);
      console.error("Error in drafting chat:", errorMessage);
      yield { type: "RUN_ERROR", message: errorMessage };
    }

    yield { type: "RUN_FINISHED", runId, threadId };
  }

  /**
   * Resets the conversation thread.
   * Mirrors DraftingPlugin::reset() (lines 189-199).
   */
  reset(threadId?: string): { threadId: string } {
    if (threadId) {
      this.store.delete(threadId);
    }
    return { threadId: randomUUID() };
  }

  /**
   * Mock: saves a draft as a new node.
   * Mirrors DraftingPlugin::save() (lines 204-228).
   * Returns fake nodeId and previewUrl since there is no CMS.
   */
  save(body: {
    entityTypeId?: string;
    bundle?: string;
    fields?: Record<string, unknown>;
  }): { nodeId: string; previewUrl: string } {
    const nodeId = String(
      Math.floor(Math.random() * 90000) + 10000,
    );
    return {
      nodeId,
      previewUrl: `/node/${nodeId}/latest`,
    };
  }

  // -- Private methods -------------------------------------------

  /**
   * Builds the system prompt with content type schema.
   * Mirrors DraftingPlugin::buildSystemPrompt() (lines 233-273).
   */
  private buildSystemPrompt(
    schema: ContentTypeSchema | null,
  ): string {
    let prompt = `You are a content drafting assistant for a CMS editorial workflow.

You can have normal conversations with the editor. Answer questions, discuss
ideas, and help them plan their content. Only use the draft_content tool when
the editor explicitly asks you to generate or draft content.

When the editor asks you to draft or generate content:
- Use the draft_content tool to return structured field values matching the
  content type schema provided below.
- Always return the COMPLETE set of fields in every tool call, not just the
  ones you changed. The frontend replaces the entire draft on each call.
- When the editor asks to regenerate specific fields, return the full draft
  with those fields updated and all other fields unchanged.
- Do NOT produce values for entity reference fields (media, taxonomies, etc.).
  These are handled separately by the editor.
- For formatted text fields, produce clean HTML appropriate for the field.
- Match the language and tone the editor requests.

When the editor asks a question, makes a comment, or has a conversation that
does not involve generating content, respond normally in plain text. Do NOT
call the draft_content tool for conversational responses.`;

    if (schema) {
      prompt +=
        "\n\nContent type schema:\n" + JSON.stringify(schema);
    }

    return prompt;
  }

  /**
   * Builds tool definitions for the Mistral chat call.
   * Mirrors DraftingPlugin::buildTools() (lines 315-332).
   */
  private buildTools() {
    return [
      {
        type: "function" as const,
        function: {
          name: "draft_content",
          description:
            "Produce a complete set of field values for the "
            + "content type. Field names and value shapes must "
            + "match the content type schema provided in the "
            + "system prompt. Always return ALL fields.",
          parameters: {
            type: "object" as const,
            properties: {
              fields: {
                type: "object",
                description:
                  "Complete field values keyed by field "
                  + "machine name.",
              },
            },
            required: ["fields"],
          },
        },
      },
    ];
  }

  /**
   * Builds a flat field index from the content type schema.
   * Mirrors DraftingPlugin::buildFieldIndex() (lines 290-307).
   */
  private buildFieldIndex(
    schema: ContentTypeSchema | null,
  ): FieldIndex {
    if (!schema) return {};
    const index: FieldIndex = {};
    for (const group of schema.groups) {
      for (const field of group.fields) {
        index[field.name] = field;
      }
    }
    return index;
  }

  /**
   * Tool handler: draft_content.
   * Mirrors DraftingPlugin::toolDraftContent() (lines 711-718).
   */
  private toolDraftContent(args: Record<string, unknown>): {
    success: boolean;
    fields: Record<string, unknown>;
    message: string;
  } {
    const fields = (args.fields ?? args) as Record<
      string,
      unknown
    >;
    return {
      success: true,
      fields,
      message:
        "Draft content generated with "
        + Object.keys(fields).length
        + " fields.",
    };
  }

  /**
   * Determines whether a field should be streamed word-by-word.
   * Mirrors DraftingPlugin::isStreamableField() (lines 697-704).
   */
  private isStreamableField(
    name: string,
    value: unknown,
    fieldIndex: FieldIndex,
  ): boolean {
    const widget = fieldIndex[name]?.widget ?? "";
    if (
      ["textarea", "textarea_formatted", "textarea_formatted_summary"]
        .includes(widget)
    ) {
      return true;
    }
    // Fallback: if the field is not in the schema (LLM invented
    // its own field names), stream any long string. This ensures
    // progressive streaming works even without a matching schema.
    if (!fieldIndex[name]) {
      if (typeof value === "string" && value.length > 50) {
        return true;
      }
      if (
        value !== null &&
        typeof value === "object" &&
        "value" in (value as Record<string, unknown>)
      ) {
        const inner = (value as Record<string, unknown>).value;
        if (typeof inner === "string" && inner.length > 50) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Yields AG-UI events for drafted fields using incremental
   * streaming: empty snapshot, add/replace deltas, final snapshot.
   * Mirrors the PHP backend's incremental field streaming.
   */
  private *streamFieldEvents(
    fields: Record<string, unknown>,
    fieldIndex: FieldIndex,
    fieldsToStream: string[],
  ): Generator<AgUiEvent> {
    // Filter to target fields on regeneration.
    let targetFields = fields;
    if (fieldsToStream.length > 0) {
      targetFields = Object.fromEntries(
        Object.entries(fields).filter(([name]) =>
          fieldsToStream.includes(name),
        ),
      );
    }

    // Phase 1: Empty initial snapshot. Fields are added
    // incrementally via deltas, not pre-populated.
    yield {
      type: "STATE_SNAPSHOT",
      snapshot: { draftedFields: {} },
    };

    // Accumulate complete state for the final snapshot.
    const completedFields: Record<string, unknown> = {};

    // Phase 2: Emit incremental deltas for each field.
    for (const [name, value] of Object.entries(targetFields)) {
      // Escape for JSON Pointer (RFC 6901). Drupal field
      // names are [a-z0-9_] only so this is defensive.
      const escaped = name
        .replace(/~/g, "~0")
        .replace(/\//g, "~1");

      const isProgressive = this.isStreamableField(
        name,
        value,
        fieldIndex,
      );

      if (
        isProgressive &&
        typeof value === "string" &&
        value.length > 50
      ) {
        // Progressive plain string: add with first word,
        // then replace as content grows.
        const words = value.split(/(\s+)/);
        let partial = "";
        for (let i = 0; i < words.length; i++) {
          partial += words[i];
          yield {
            type: "STATE_DELTA",
            delta: [
              {
                // First token adds the field; subsequent
                // tokens replace the growing value.
                op: i === 0 ? "add" : "replace",
                path: `/draftedFields/${escaped}`,
                value: partial,
              },
            ],
          };
        }
        completedFields[name] = value;
      } else if (
        isProgressive &&
        value !== null &&
        typeof value === "object" &&
        "value" in (value as Record<string, unknown>)
      ) {
        // Progressive formatted text: add the full object
        // on first appearance, then replace inner value.
        const obj = value as Record<string, unknown>;
        const inner = String(obj.value ?? "");

        if (inner.length > 50) {
          const words = inner.split(/(\s+)/);
          let partial = "";
          for (let i = 0; i < words.length; i++) {
            partial += words[i];
            if (i === 0) {
              // First appearance: add the whole object
              // with partial inner value.
              yield {
                type: "STATE_DELTA",
                delta: [
                  {
                    op: "add",
                    path: `/draftedFields/${escaped}`,
                    value: { ...obj, value: partial },
                  },
                ],
              };
            } else {
              // Subsequent tokens: replace just the
              // inner value path.
              yield {
                type: "STATE_DELTA",
                delta: [
                  {
                    op: "replace",
                    path: `/draftedFields/${escaped}/value`,
                    value: partial,
                  },
                ],
              };
            }
          }
        } else {
          // Short formatted text: add with final value.
          yield {
            type: "STATE_DELTA",
            delta: [
              {
                op: "add",
                path: `/draftedFields/${escaped}`,
                value,
              },
            ],
          };
        }
        completedFields[name] = value;
      } else {
        // Non-progressive field: add with final value.
        yield {
          type: "STATE_DELTA",
          delta: [
            {
              op: "add",
              path: `/draftedFields/${escaped}`,
              value,
            },
          ],
        };
        completedFields[name] = value;
      }
    }

    // Phase 3: Final snapshot with complete state.
    yield {
      type: "STATE_SNAPSHOT",
      snapshot: { draftedFields: { ...completedFields } },
    };
  }

  /**
   * Executes tool calls and yields AG-UI events.
   * Mirrors DraftingPlugin::executeToolCalls() (lines 585-614).
   *
   * Uses a synchronous generator so the caller (runLlmLoop) can
   * manually iterate to extract both yielded events AND the
   * return value (tool result messages for conversation history).
   */
  private *executeToolCalls(
    toolCalls: Array<{
      id: string;
      name: string;
      arguments: Record<string, unknown>;
    }>,
    fieldIndex: FieldIndex,
    fieldsToStream: string[],
  ): Generator<AgUiEvent, ToolMessage[]> {
    const toolMessages: ToolMessage[] = [];

    for (const toolCall of toolCalls) {
      const result =
        toolCall.name === "draft_content"
          ? this.toolDraftContent(toolCall.arguments)
          : { error: `Unknown tool: ${toolCall.name}` };

      toolMessages.push({
        role: "tool" as const,
        content: JSON.stringify(result),
        toolCallId: toolCall.id,
      });

      // Stream field events if the tool produced fields.
      if ("fields" in result && result.fields) {
        yield* this.streamFieldEvents(
          result.fields as Record<string, unknown>,
          fieldIndex,
          fieldsToStream,
        );
      }

      yield {
        type: "TOOL_CALL_END",
        toolCallId: toolCall.id,
      };
    }

    return toolMessages;
  }

  /**
   * Runs the LLM with tool loop, yielding AG-UI events.
   * Mirrors DraftingPlugin::runLlmLoop() (lines 418-570).
   *
   * @param systemPrompt - The system prompt with schema.
   * @param messages - Conversation history (mutated in place).
   * @param fieldIndex - Flat field index for streaming.
   * @param fieldsToStream - Field names to stream progressively.

   * @returns The final assistant text message.
   */
  private async *runLlmLoop(
    systemPrompt: string,
    messages: ChatMessage[],
    fieldIndex: FieldIndex,
    fieldsToStream: string[],
  ): AsyncGenerator<AgUiEvent, string> {
    let fullMessage = "";

    for (let i = 0; i < DraftingService.MAX_ITERATIONS; i++) {

      let messageId = randomUUID();

      // Build the messages array for the Mistral API call.
      const apiMessages: ChatMessage[] = [
        { role: "system" as const, content: systemPrompt },
        ...messages,
      ];

      // Call Mistral with streaming.
      const stream = await this.mistral.chat.stream({
        model: MISTRAL_MODEL,
        messages: apiMessages,
        tools: this.buildTools(),
      });

      let streamedText = "";
      let messageStarted = false;

      // Accumulate tool call deltas across streaming chunks.
      const toolCallMap = new Map<
        number,
        { id: string; name: string; arguments: string }
      >();

      // Iterate over streaming chunks from the Mistral API.
      for await (const event of stream) {
  
        const delta = event.data.choices[0]?.delta;
        if (!delta) continue;

        // Text content deltas.
        const text = delta.content;
        if (typeof text === "string" && text.length > 0) {
          if (!messageStarted) {
            yield {
              type: "TEXT_MESSAGE_START",
              messageId,
              role: "assistant",
            };
            messageStarted = true;
          }
          yield {
            type: "TEXT_MESSAGE_CONTENT",
            messageId,
            delta: text,
          };
          streamedText += text;
        }

        // Tool call deltas: accumulate fragments until the
        // stream ends, then assemble complete tool calls.
        if (delta.toolCalls) {
          for (const tc of delta.toolCalls) {
            const idx = tc.index ?? 0;
            const existing = toolCallMap.get(idx);
            if (!existing) {
              toolCallMap.set(idx, {
                id: tc.id ?? randomUUID(),
                name: tc.function?.name ?? "",
                arguments: String(
                  tc.function?.arguments ?? "",
                ),
              });
            } else {
              if (tc.function?.name) {
                existing.name += tc.function.name;
              }
              if (tc.function?.arguments != null) {
                existing.arguments += String(
                  tc.function.arguments,
                );
              }
            }
          }
        }
      }

      // Close text message if one was started.
      if (messageStarted) {
        yield { type: "TEXT_MESSAGE_END", messageId };
      }


      // Process assembled tool calls (if any).
      const assembledTools = Array.from(toolCallMap.values());
      if (assembledTools.length > 0) {
        // Build tool calls array for the assistant message history.
        const toolCallsForHistory = assembledTools.map((tc) => ({
          id: tc.id,
          type: "function" as const,
          function: {
            name: tc.name,
            arguments: tc.arguments,
          },
        }));

        // Yield TOOL_CALL_START events for each tool call.
        for (const tc of assembledTools) {
          yield {
            type: "TOOL_CALL_START",
            toolCallId: tc.id,
            toolCallName: tc.name,
          };
        }

        // Parse accumulated argument strings into objects.
        const parsedToolCalls = assembledTools.map((tc) => ({
          id: tc.id,
          name: tc.name,
          arguments: JSON.parse(tc.arguments) as Record<
            string,
            unknown
          >,
        }));

        // Execute tools and yield STATE_SNAPSHOT + TOOL_CALL_END
        // events. Manual iteration is needed to extract the
        // generator's return value (tool result messages).
        const gen = this.executeToolCalls(
          parsedToolCalls,
          fieldIndex,
          fieldsToStream,
        );
        let genResult = gen.next();
        while (!genResult.done) {
          yield genResult.value;
          genResult = gen.next();
        }
        const toolMessages = genResult.value;

        // Add assistant + tool messages to history for the next
        // iteration's context.
        const assistantMsg: AssistantMessage = {
          role: "assistant" as const,
          content: streamedText || "",
          toolCalls: toolCallsForHistory,
        };
        messages.push(assistantMsg);
        messages.push(...toolMessages);

        // Generate new messageId for the next iteration.
        messageId = randomUUID();
        continue;
      }

      // No tool calls: pure text response. Save and break.
      fullMessage = streamedText;
      break;
    }

    return fullMessage;
  }
}
