/**
 * Shared drafting service contracts and the Mistral-backed
 * drafting implementation used by the local integration mode.
 */

import { randomUUID } from "node:crypto";
import type { Mistral } from "@mistralai/mistralai";
import type {
  AssistantMessage,
  SystemMessage,
  ToolMessage,
  UserMessage,
} from "@mistralai/mistralai/models/components";
import { MISTRAL_MODEL } from "../config";
import type { ChatMessage, ConversationStore } from "./conversation-store";

// -- Types -------------------------------------------------------

/** Data Stream Protocol event yielded by a drafting service. */
export interface StreamEvent {
  type: string;
  [key: string]: unknown;
}

/** A single field definition from the content type schema. */
export interface SchemaField {
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
export interface ContentTypeSchema {
  contentType: string;
  label: string;
  groups: { label: string; fields: SchemaField[] }[];
}

/** Flattened field lookup keyed by machine name. */
export type FieldIndex = Record<string, SchemaField>;

/** Options for the chat() method. */
export interface ChatOptions {
  message: string;
  threadId?: string;
  entityTypeId: string;
  bundle: string;
  schema: ContentTypeSchema | null;
}

/** Request body for the mock save action. */
export interface DraftSavePayload {
  entityTypeId?: string;
  bundle?: string;
  fields?: Record<string, unknown>;
}

/** Mock save result returned by the dev server. */
export interface DraftSaveResult {
  nodeId: string;
  previewUrl: string;
}

/** Common interface implemented by all drafting services. */
export interface DraftingService {
  chat(opts: ChatOptions): AsyncGenerator<StreamEvent>;
  reset(threadId?: string): { threadId: string };
  save(body: DraftSavePayload): DraftSaveResult;
}

/**
 * Parse the optional "[fields:field_a,field_b]" tag from a
 * drafting prompt. The frontend appends this when requesting a
 * targeted regeneration.
 */
export function extractFieldsToStream(message: string): string[] {
  const match = message.match(/\[fields:([^\]]+)\]/);
  if (!match?.[1]) {
    return [];
  }

  return match[1]
    .split(",")
    .map((field) => field.trim())
    .filter(Boolean);
}

type MistralApiMessage =
  | SystemMessage
  | UserMessage
  | ToolMessage
  | (AssistantMessage & { role: "assistant" });

/**
 * Drafting service: AI-powered content drafting via Mistral.
 *
 * Mirrors DraftingPlugin.php method-for-method. Each method has
 * a JSDoc comment referencing the corresponding PHP method. The
 * service yields Data Stream Protocol events as an AsyncGenerator;
 * the route handler writes them to the SSE response.
 */
export class MistralDraftingService implements DraftingService {
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
   * Streams AI chat responses via Data Stream Protocol SSE events.
   * Mirrors DraftingPlugin::chat() (lines 128-184) and
   * createStreamResponse() (lines 340-406).
   */
  async *chat(opts: ChatOptions): AsyncGenerator<StreamEvent> {
    const { message, threadId: inputThreadId, schema } = opts;

    const messageId = randomUUID();
    const threadId = inputThreadId || randomUUID();
    const systemPrompt = this.buildSystemPrompt(schema);
    const fieldIndex = this.buildFieldIndex(schema);
    const fieldsToStream = extractFieldsToStream(message);

    yield { type: "start", messageId };

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
      const assistantText = result.value;

      // Save updated history.
      if (assistantText) {
        history.push({
          role: "assistant" as const,
          content: assistantText,
        });
      }
      this.store.save(threadId, history);
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : String(err);
      console.error("Error in drafting chat:", errorMessage);
      yield { type: "error", errorText: errorMessage };
    }

    yield {
      type: "finish",
      finishReason: "stop",
      usage: { inputTokens: 0, outputTokens: 0 },
    };
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
  save(_body: DraftSavePayload): DraftSaveResult {
    const nodeId = String(Math.floor(Math.random() * 90000) + 10000);
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
  private buildSystemPrompt(schema: ContentTypeSchema | null): string {
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
      prompt += `\n\nContent type schema:\n${JSON.stringify(schema)}`;
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
            "Produce a complete set of field values for the " +
            "content type. Field names and value shapes must " +
            "match the content type schema provided in the " +
            "system prompt. Always return ALL fields.",
          parameters: {
            type: "object" as const,
            properties: {
              fields: {
                type: "object",
                description:
                  "Complete field values keyed by field " + "machine name.",
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
  private buildFieldIndex(schema: ContentTypeSchema | null): FieldIndex {
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
    const fields = (args.fields ?? args) as Record<string, unknown>;
    return {
      success: true,
      fields,
      message:
        "Draft content generated with " +
        Object.keys(fields).length +
        " fields.",
    };
  }

  /**
   * Yields a data-drafted-fields event with all drafted field
   * values.
   *
   * Mirrors the PHP backend behavior: all fields arrive as a
   * single snapshot after the tool call completes. No incremental
   * streaming -- Mistral sends tool call arguments in one shot.
   *
   * Any progressive display (typewriter effect) is handled
   * entirely on the frontend.
   */
  private *streamFieldEvents(
    fields: Record<string, unknown>,
    _fieldIndex: FieldIndex,
    fieldsToStream: string[],
  ): Generator<StreamEvent> {
    // Filter to target fields on regeneration.
    let targetFields = fields;
    if (fieldsToStream.length > 0) {
      targetFields = Object.fromEntries(
        Object.entries(fields).filter(([name]) =>
          fieldsToStream.includes(name),
        ),
      );
    }

    // Emit a single snapshot with all field values at once.
    // No transient flag -- this is the final reconciliation.
    yield {
      type: "data-drafted-fields",
      data: { ...targetFields },
    };
  }

  /**
   * Executes tool calls and yields Data Stream Protocol events.
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
  ): Generator<StreamEvent, ToolMessage[]> {
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

      // tool-call-end closes the tool call, then tool-result
      // tells the client the tool completed successfully.
      yield { type: "tool-call-end" };
      yield {
        type: "tool-result",
        toolCallId: toolCall.id,
        result,
      };
    }

    return toolMessages;
  }

  /**
   * Runs the LLM with tool loop, yielding Data Stream Protocol
   * events. Mirrors DraftingPlugin::runLlmLoop() (lines 418-570).
   *
   * @param systemPrompt - The system prompt with schema.
   * @param messages - Conversation history (mutated in place).
   * @param fieldIndex - Flat field index for streaming.
   * @param fieldsToStream - Field names to stream progressively.
   *
   * @returns The final assistant text message.
   */
  private async *runLlmLoop(
    systemPrompt: string,
    messages: ChatMessage[],
    fieldIndex: FieldIndex,
    fieldsToStream: string[],
  ): AsyncGenerator<StreamEvent, string> {
    let fullMessage = "";

    for (let i = 0; i < MistralDraftingService.MAX_ITERATIONS; i++) {
      let textPartId = randomUUID();

      // Emit start-step before each LLM call.
      yield { type: "start-step" };

      // Build the messages array for the Mistral API call.
      const apiMessages = [
        { role: "system" as const, content: systemPrompt },
        ...messages,
      ] as MistralApiMessage[];

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
              type: "text-start",
              id: textPartId,
            };
            messageStarted = true;
          }
          yield {
            type: "text-delta",
            textDelta: text,
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
                arguments: String(tc.function?.arguments ?? ""),
              });
            } else {
              if (tc.function?.name) {
                existing.name += tc.function.name;
              }
              if (tc.function?.arguments != null) {
                existing.arguments += String(tc.function.arguments);
              }
            }
          }
        }
      }

      // Close text part if one was started.
      if (messageStarted) {
        yield { type: "text-end" };
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

        // Yield tool-call-start events for each tool call.
        for (const tc of assembledTools) {
          yield {
            type: "tool-call-start",
            id: randomUUID(),
            toolCallId: tc.id,
            toolName: tc.name,
          };
        }

        // Parse accumulated argument strings into objects.
        const parsedToolCalls = assembledTools.map((tc) => ({
          id: tc.id,
          name: tc.name,
          arguments: JSON.parse(tc.arguments) as Record<string, unknown>,
        }));

        // Execute tools and yield data-drafted-fields +
        // tool-result events. Manual iteration is needed
        // to extract the generator's return value (tool result
        // messages).
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

        // Emit finish-step after the tool execution completes.
        yield {
          type: "finish-step",
          finishReason: "tool-calls",
          usage: { inputTokens: 0, outputTokens: 0 },
          isContinued: true,
        };

        // Add assistant + tool messages to history for the next
        // iteration's context.
        const assistantMsg: AssistantMessage = {
          role: "assistant" as const,
          content: streamedText || "",
          toolCalls: toolCallsForHistory,
        };
        messages.push(assistantMsg);
        messages.push(...toolMessages);

        // Generate new text part ID for the next iteration.
        textPartId = randomUUID();
        continue;
      }

      // No tool calls: pure text response. Emit finish-step,
      // save and break.
      yield {
        type: "finish-step",
        finishReason: "stop",
        usage: { inputTokens: 0, outputTokens: 0 },
        isContinued: false,
      };
      fullMessage = streamedText;
      break;
    }

    return fullMessage;
  }
}
