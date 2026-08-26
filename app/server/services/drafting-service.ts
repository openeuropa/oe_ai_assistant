/**
 * Shared drafting service contracts and the Mistral-backed
 * drafting implementation for the local dev server.
 *
 * Mirrors the Drupal backend's orchestration flow:
 * 1. Router LLM calls get_content_schema (schema splitting)
 * 2. Router LLM calls draft_content (orchestration signal)
 * 3. Orchestrator dispatches one Mistral call per field group
 * 4. Results consolidated and emitted as data-drafted-fields
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
import type {
  ChatMessage,
  ConversationStore,
  TranscriptMessage,
} from "./conversation-store";

// -- Types -------------------------------------------------------

/** Data Stream Protocol event yielded by a drafting service. */
export interface StreamEvent {
  type: string;
  [key: string]: unknown;
}

/**
 * Content type schema from EntityJsonSchemaComposer.
 * JSON Schema with a flat properties map keyed by field name.
 */
export interface ContentTypeSchema {
  type: string;
  properties: Record<string, Record<string, unknown>>;
}

/** Flattened field lookup keyed by machine name. */
export type FieldIndex = Record<string, Record<string, unknown>>;

/** A named group of fields for sub-agent orchestration. */
interface SchemaGroup {
  groupId: string;
  label: string;
  fieldNames: string[];
  schemaSlice: { type: string; properties: Record<string, unknown> };
}

/** Options for the chat() method. */
export interface ChatOptions {
  message: string;
  sessionId: string;
  entityTypeId: string;
  bundle: string;
  schema: ContentTypeSchema | null;
}

/** Request body for the save action: names a session draft version. */
export interface DraftSavePayload {
  sessionId?: string;
  version?: number;
}

/** Mock save result returned by the dev server. */
export interface DraftSaveResult {
  nodeId: string;
  previewUrl: string;
}

/** Common interface implemented by all drafting services. */
export interface DraftingService {
  chat(opts: ChatOptions): AsyncGenerator<StreamEvent>;
  reset(sessionId: string): { status: string };
  /** Returns null when the session has no draft with that version. */
  save(body: DraftSavePayload): DraftSaveResult | null;
  getMessages(sessionId: string): TranscriptMessage[];
}

/**
 * Emits a completed draft_content tool call carrying the drafted fields.
 *
 * Matches the Drupal UiMessageStream::toolCall() sequence so the drafting
 * card renders inline with the fields as its clickable result.
 */
export async function* emitDraftToolCall(
  result: Record<string, unknown>,
): AsyncGenerator<StreamEvent> {
  const toolCallId = randomUUID();
  yield {
    type: "tool-call-start",
    id: toolCallId,
    toolCallId,
    toolName: "draft_content",
  };
  yield { type: "tool-call-delta", argsText: "{}" };
  yield { type: "tool-call-end" };
  yield { type: "tool-result", toolCallId, result };
}

/**
 * Parse the optional "[fields:field_a,field_b]" tag from a
 * drafting prompt.
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

// -- Schema splitting (mirrors EntityJsonSchemaComposer) ----------

/** Entity types that are simple references, not draftable inline. */
const SIMPLE_REFERENCE_TYPES = new Set([
  "taxonomy_term",
  "media",
  "file",
  "user",
]);

/**
 * Splits a content type schema into named field groups.
 * Mirrors EntityJsonSchemaComposer::splitSchemaIntoGroups().
 */
function splitSchemaIntoGroups(schema: ContentTypeSchema): SchemaGroup[] {
  const properties = schema.properties ?? {};
  const mainFields: Record<string, Record<string, unknown>> = {};
  const entityGroups: SchemaGroup[] = [];

  for (const [fieldName, fieldSchema] of Object.entries(properties)) {
    const items = (fieldSchema.items ?? {}) as Record<string, unknown>;
    const targetType = items["x-targetType"] as string | undefined;

    if (targetType && !SIMPLE_REFERENCE_TYPES.has(targetType)) {
      entityGroups.push({
        groupId: fieldName,
        label: fieldName
          .replace(/^field_/, "")
          .replace(/_/g, " ")
          .replace(/\b\w/g, (c) => c.toUpperCase()),
        fieldNames: [fieldName],
        schemaSlice: {
          type: "object",
          properties: { [fieldName]: fieldSchema },
        },
      });
    } else {
      mainFields[fieldName] = fieldSchema;
    }
  }

  const groups: SchemaGroup[] = [];

  if (Object.keys(mainFields).length > 0) {
    groups.push({
      groupId: "main_fields",
      label: "Main fields",
      fieldNames: Object.keys(mainFields),
      schemaSlice: { type: "object", properties: mainFields },
    });
  }

  groups.push(...entityGroups);
  return groups;
}

// -- Mistral service ----------------------------------------------

type MistralApiMessage =
  | SystemMessage
  | UserMessage
  | ToolMessage
  | (AssistantMessage & { role: "assistant" });

/**
 * Drafting service: AI-powered content drafting via Mistral.
 *
 * Implements the two-tool conversational flow:
 * 1. Router LLM decides to call get_content_schema or
 *    draft_content based on conversation
 * 2. get_content_schema returns schema groups
 * 3. draft_content triggers sub-agent orchestration: one
 *    Mistral call per schema group with structured output
 */
export class MistralDraftingService implements DraftingService {
  private static readonly MAX_ITERATIONS = 10;

  constructor(
    private readonly mistral: Mistral,
    private readonly store: ConversationStore,
  ) {}

  async *chat(opts: ChatOptions): AsyncGenerator<StreamEvent> {
    const { message, sessionId, schema } = opts;

    const messageId = randomUUID();
    const groups = schema ? splitSchemaIntoGroups(schema) : [];
    const systemPrompt = this.buildSystemPrompt(groups, opts);

    yield { type: "start", messageId };

    try {
      const history = this.store.load(sessionId);
      history.push({ role: "user" as const, content: message });

      // Run the router LLM with tool loop.
      const gen = this.runRouterLoop(systemPrompt, history, groups);
      let result = await gen.next();
      while (!result.done) {
        yield result.value;
        result = await gen.next();
      }
      const assistantText = result.value;

      if (assistantText) {
        history.push({
          role: "assistant" as const,
          content: assistantText,
        });
      }
      this.store.save(sessionId, history);
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

  reset(sessionId: string): { status: string } {
    this.store.delete(sessionId);
    return { status: "ok" };
  }

  getMessages(sessionId: string): TranscriptMessage[] {
    return this.store.getTranscript(sessionId);
  }

  save(_body: DraftSavePayload): DraftSaveResult | null {
    // This dev service keeps no draft history; accept any version.
    const nodeId = String(Math.floor(Math.random() * 90000) + 10000);
    return { nodeId, previewUrl: `/node/${nodeId}/latest` };
  }

  // -- Private: system prompt and tools ---------------------------

  private buildSystemPrompt(groups: SchemaGroup[], opts: ChatOptions): string {
    // Must match config/install/ai_agents.ai_agent.oe_drafting_router.yml
    let prompt = `You are a content drafting assistant for a CMS editorial workflow.

You have two tools available:

1. get_content_schema: Call this first to discover what fields are
   available for the content type. It returns groups of fields
   with their types and descriptions. Use the bundle and
   entity_type_id values provided below.

2. draft_content: Call this ONLY when you have gathered ALL the
   information needed to fill every field in the schema. This
   triggers the content generation process.

Workflow:
- When the user asks to draft content, call get_content_schema
  to see what fields are available.
- Review ALL field groups carefully. For each group, determine
  whether you have enough information from the conversation to
  generate meaningful content. If ANY field group lacks context,
  ask the user about it specifically.
- Do NOT proceed to draft_content until you have addressed every
  field group. Ask the user about each group you are unsure about.
  For example: if there is a contacts group, ask the user for
  contact details. If there is a paragraphs group, ask what
  content sections they want.
- The user may tell you to skip certain fields, use your best
  judgment, or just go ahead. In that case, proceed with
  draft_content using whatever context you have.
- Only call draft_content when either:
  (a) you have gathered specific information for all field groups, or
  (b) the user has explicitly told you to proceed without it.
- You can have normal conversations with the user at any point.`;

    // Append bundle/entityTypeId context (mirrors DraftingPlugin).
    prompt +=
      `\n\nContent type context:\n` +
      `bundle: ${opts.bundle}\n` +
      `entity_type_id: ${opts.entityTypeId}\n`;

    // Append the field groups so the router has the same schema
    // context as in production (mirrors DraftingPlugin, which
    // appends splitSchemaIntoGroups() output to the prompt).
    if (groups.length > 0) {
      prompt += `\nAvailable field groups:\n${JSON.stringify(groups, null, 2)}\n`;
    }

    return prompt;
  }

  private buildRouterTools() {
    return [
      {
        type: "function" as const,
        function: {
          name: "get_content_schema",
          description:
            "Returns the content type schema split into field groups.",
          parameters: {
            type: "object" as const,
            properties: {
              bundle: { type: "string", description: "Bundle machine name." },
              entity_type_id: { type: "string", description: "Entity type." },
            },
            required: ["bundle", "entity_type_id"],
          },
        },
      },
      {
        type: "function" as const,
        function: {
          name: "draft_content",
          description:
            "Signal that you are ready to generate the content draft.",
          parameters: { type: "object" as const, properties: {} },
        },
      },
    ];
  }

  // -- Private: router LLM loop -----------------------------------

  /**
   * Runs the router LLM with a tool loop. Handles get_content_schema
   * (returns schema groups) and draft_content (triggers orchestration).
   */
  private async *runRouterLoop(
    systemPrompt: string,
    messages: ChatMessage[],
    groups: SchemaGroup[],
  ): AsyncGenerator<StreamEvent, string> {
    let fullMessage = "";

    for (let i = 0; i < MistralDraftingService.MAX_ITERATIONS; i++) {
      yield { type: "start-step" };

      const apiMessages = [
        { role: "system" as const, content: systemPrompt },
        ...messages,
      ] as MistralApiMessage[];

      const stream = await this.mistral.chat.stream({
        model: MISTRAL_MODEL,
        messages: apiMessages,
        tools: this.buildRouterTools(),
      });

      let streamedText = "";
      let messageStarted = false;
      const toolCallMap = new Map<
        number,
        { id: string; name: string; arguments: string }
      >();

      for await (const event of stream) {
        const delta = event.data.choices[0]?.delta;
        if (!delta) continue;

        const text = delta.content;
        if (typeof text === "string" && text.length > 0) {
          if (!messageStarted) {
            yield { type: "text-start", id: randomUUID() };
            messageStarted = true;
          }
          yield { type: "text-delta", textDelta: text };
          streamedText += text;
        }

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
              if (tc.function?.name) existing.name += tc.function.name;
              if (tc.function?.arguments != null)
                existing.arguments += String(tc.function.arguments);
            }
          }
        }
      }

      if (messageStarted) yield { type: "text-end" };

      const assembledTools = Array.from(toolCallMap.values());
      if (assembledTools.length > 0) {
        const toolCallsForHistory = assembledTools.map((tc) => ({
          id: tc.id,
          type: "function" as const,
          function: { name: tc.name, arguments: tc.arguments },
        }));

        // Check if draft_content was called.
        const draftCall = assembledTools.find(
          (tc) => tc.name === "draft_content",
        );

        if (draftCall) {
          // Emit finish-step for the router, then orchestrate.
          yield {
            type: "finish-step",
            finishReason: "tool-calls",
            usage: { inputTokens: 0, outputTokens: 0 },
            isContinued: false,
          };

          // Run sub-agent orchestration, then keep a confirmation as the
          // assistant turn so the conversation store has a text turn.
          yield* this.orchestrate(groups, messages);
          fullMessage = "Draft generated. Review the content on the right.";
          break;
        }

        // Handle get_content_schema: return groups as tool result.
        const toolMessages: ToolMessage[] = [];
        for (const tc of assembledTools) {
          let result: unknown;
          if (tc.name === "get_content_schema") {
            result = groups.map((g) => ({
              groupId: g.groupId,
              label: g.label,
              fieldNames: g.fieldNames,
            }));
          } else {
            result = { error: `Unknown tool: ${tc.name}` };
          }
          toolMessages.push({
            role: "tool" as const,
            content: JSON.stringify(result),
            toolCallId: tc.id,
          });
        }

        yield {
          type: "finish-step",
          finishReason: "tool-calls",
          usage: { inputTokens: 0, outputTokens: 0 },
          isContinued: true,
        };

        const assistantMsg: AssistantMessage = {
          role: "assistant" as const,
          content: streamedText || "",
          toolCalls: toolCallsForHistory,
        };
        messages.push(assistantMsg);
        messages.push(...toolMessages);
        continue;
      }

      // No tool calls: pure text response.
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

  // -- Private: sub-agent orchestration ---------------------------

  /**
   * Dispatches one Mistral call per schema group and consolidates
   * results. Mirrors DraftingPlugin::orchestrate().
   */
  private async *orchestrate(
    groups: SchemaGroup[],
    history: ChatMessage[],
  ): AsyncGenerator<StreamEvent> {
    if (groups.length === 0) {
      yield { type: "text-delta", textDelta: "No fields available." };
      return;
    }

    // Emit initial plan (all pending).
    const plan = groups.map((g) => ({
      stepId: g.groupId,
      label: g.label,
      status: "pending",
    }));
    yield { type: "data-plan", data: [...plan] };

    // Build conversation context for sub-agents.
    const conversationContext = history
      .map((m) => `${m.role}: ${m.content}`)
      .join("\n");

    const results: Record<string, Record<string, unknown>> = {};
    let mainFieldsResult = "";

    for (let i = 0; i < groups.length; i++) {
      const group = groups[i];
      const stepId = group.groupId;

      // Update plan: in_progress.
      plan[i].status = "in_progress";
      yield { type: "data-plan", data: [...plan] };

      try {
        // Build the sub-agent prompt.
        let taskPrompt = `Conversation context:\n${conversationContext}\n\n`;
        if (stepId !== "main_fields" && mainFieldsResult) {
          taskPrompt += `Main fields already generated:\n${mainFieldsResult}\n\n`;
        }
        taskPrompt +=
          "Generate content for the fields in the provided schema. " +
          "Return ONLY valid JSON conforming to the schema. " +
          "No markdown fencing, no explanation.";

        // Must match config/install/ai_agents.ai_agent.oe_content_drafter.yml
        const subAgentMessages: MistralApiMessage[] = [
          {
            role: "system" as const,
            content:
              "You are a content generator. You will receive a JSON schema and\n" +
              "instructions describing what content to produce. Generate a JSON\n" +
              "object that conforms exactly to the given schema. Return ONLY\n" +
              "valid JSON with no markdown fencing, no explanation, no commentary.\n\n" +
              "Field values must be arrays of items where each item is an object\n" +
              'with property keys (e.g. "title": [{"value": "Headline"}],\n' +
              '"body": [{"value": "<p>Text</p>", "format": "full_html"}]).\n' +
              "Match the schema's array/object/property shape exactly.\n\n" +
              "For formatted text fields, produce clean HTML.\n" +
              "Match the language and tone described in the instructions.\n\n" +
              `Schema:\n${JSON.stringify(group.schemaSlice)}`,
          },
          { role: "user" as const, content: taskPrompt },
        ];

        // Call Mistral for this group (non-streaming, structured).
        const response = await this.mistral.chat.complete({
          model: MISTRAL_MODEL,
          messages: subAgentMessages,
          responseFormat: { type: "json_object" },
        });

        const fullText =
          response.choices?.[0]?.message?.content?.toString() ?? "";

        // Parse JSON result.
        try {
          const parsed = JSON.parse(fullText) as Record<string, unknown>;
          results[stepId] = parsed;
          if (stepId === "main_fields") {
            mainFieldsResult = fullText;
          }
        } catch {
          console.error(
            `[orchestrate] Failed to parse JSON for ${stepId}:`,
            fullText.substring(0, 200),
          );
        }

        // Mark done.
        plan[i].status = "done";
        yield { type: "data-plan", data: [...plan] };
      } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        console.error(`[orchestrate] Sub-agent ${stepId} failed:`, msg);
        plan[i].status = "error";
        yield { type: "data-plan", data: [...plan] };
        yield { type: "error", errorText: msg, step: stepId };
      }
    }

    // Consolidate results into a flat fields map.
    const consolidated: Record<string, unknown> = {};
    for (const group of groups) {
      const stepId = group.groupId;
      if (!results[stepId]) continue;
      if (stepId === "main_fields") {
        Object.assign(consolidated, results[stepId]);
      } else {
        for (const fieldName of group.fieldNames) {
          if (results[stepId][fieldName]) {
            consolidated[fieldName] = results[stepId][fieldName];
          } else {
            consolidated[fieldName] = results[stepId];
          }
        }
      }
    }

    // Emit data-drafted-fields.
    yield { type: "data-drafted-fields", data: consolidated };

    // Emit the draft_content tool call with its result so the card appears
    // live in the chat.
    yield* emitDraftToolCall(consolidated);

    // Streamed confirmation text.
    const fieldCount = Object.keys(consolidated).length;
    const confirmation = `Draft generated with ${fieldCount} fields. Review the content on the right.`;
    yield { type: "start-step" };
    yield { type: "text-delta", textDelta: confirmation };
    yield {
      type: "finish-step",
      finishReason: "stop",
      usage: { inputTokens: 0, outputTokens: 0 },
      isContinued: false,
    };
  }
}
