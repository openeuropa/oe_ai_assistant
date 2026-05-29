/**
 * Fixture-backed drafting service for standalone frontend work.
 *
 * This mode never touches a provider SDK. It returns deterministic
 * SSE responses and draft payloads from local fixture files so the
 * drafting UI can be exercised without API credentials.
 */

import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { FIELD_GAP_DELAY_MS, SSE_CHUNK_DELAY_MS } from "../config";
import type { ConversationStore } from "./conversation-store";
import type {
  ChatOptions,
  DraftingService,
  DraftSavePayload,
  DraftSaveResult,
  StreamEvent,
} from "./drafting-service";
import { extractFieldsToStream } from "./drafting-service";

interface DraftFixtureVariant {
  assistantText: string;
  fields: Record<string, unknown>;
}

interface BundleDraftingFixture {
  bundle: string;
  conversationReplies: {
    default: string;
    saveWithoutDraft: string;
  };
  drafts: {
    initial: DraftFixtureVariant;
    regenerated: DraftFixtureVariant;
  };
}

const DEFAULT_USAGE = { inputTokens: 0, outputTokens: 0 };
const DRAFT_INTENT_PATTERN =
  /\b(draft|generate|write|create|prepare|regenerate|rewrite|revise|update)\b/i;
const SAVE_INTENT_PATTERN =
  /\bsave the draft as a new unpublished revision\b|\bsave\b.*\bdraft\b/i;
const FIXTURE_CACHE = new Map<string, BundleDraftingFixture | null>();

/** Delay helper used to make mock SSE behavior visible in the UI. */
function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Split text into stable deltas while preserving whitespace. */
function toTextDeltas(text: string): string[] {
  return text.match(/\S+\s*/g) ?? [text];
}

/** Load a bundle-specific drafting fixture from disk and cache it. */
function loadBundleFixture(bundle: string): BundleDraftingFixture | null {
  if (FIXTURE_CACHE.has(bundle)) {
    return FIXTURE_CACHE.get(bundle) ?? null;
  }

  const fixturePath = join(
    import.meta.dirname,
    "..",
    "fixtures",
    "drafting",
    `${bundle}.json`,
  );

  try {
    const content = readFileSync(fixturePath, "utf-8");
    const fixture = JSON.parse(content) as BundleDraftingFixture;
    FIXTURE_CACHE.set(bundle, fixture);
    return fixture;
  } catch {
    FIXTURE_CACHE.set(bundle, null);
    return null;
  }
}

/** Filter field snapshots to the requested regeneration target list. */
function selectDraftedFields(
  fields: Record<string, unknown>,
  fieldsToStream: string[],
): Record<string, unknown> {
  if (fieldsToStream.length === 0) {
    return fields;
  }

  return Object.fromEntries(
    Object.entries(fields).filter(([name]) => fieldsToStream.includes(name)),
  );
}

/** True when the prompt should trigger a mock draft fixture. */
function isDraftRequest(message: string, fieldsToStream: string[]): boolean {
  return fieldsToStream.length > 0 || DRAFT_INTENT_PATTERN.test(message);
}

/** True when the prompt is the frontend save action. */
function isSaveRequest(message: string): boolean {
  return SAVE_INTENT_PATTERN.test(message);
}

export class MockDraftingService implements DraftingService {
  private nextNodeId = 42000;
  private readonly draftFieldsByThread = new Map<
    string,
    Record<string, unknown>
  >();

  constructor(private readonly store: ConversationStore) {}

  async *chat(opts: ChatOptions): AsyncGenerator<StreamEvent> {
    const { message, threadId: inputThreadId, bundle } = opts;
    const messageId = randomUUID();
    const threadId = inputThreadId || randomUUID();
    const fieldsToStream = extractFieldsToStream(message);

    yield { type: "start", messageId };

    try {
      const history = this.store.load(threadId);
      history.push({
        role: "user" as const,
        content: message,
      });

      const fixture = bundle ? loadBundleFixture(bundle) : null;
      let assistantText = "";

      if (!bundle) {
        assistantText =
          "Mock drafting mode needs a bundle before it can load fixture data.";
        yield* this.createTextStep(assistantText);
      } else if (!fixture) {
        assistantText = `Mock drafting mode does not have a fixture for bundle "${bundle}".`;
        yield* this.createTextStep(assistantText);
      } else if (isSaveRequest(message)) {
        const fields = this.draftFieldsByThread.get(threadId);

        if (!fields) {
          assistantText = fixture.conversationReplies.saveWithoutDraft;
          yield* this.createTextStep(assistantText);
        } else {
          const result = this.save({ fields });
          assistantText =
            `Mock save complete. Created draft node ${result.nodeId}. ` +
            `Preview: ${result.previewUrl}`;
          yield* this.createSaveStep(result, assistantText);
        }
      } else if (isDraftRequest(message, fieldsToStream)) {
        const useRegeneratedVariant =
          fieldsToStream.length > 0 ||
          /\b(regenerate|rewrite|revise|update)\b/i.test(message);
        const variant = useRegeneratedVariant
          ? fixture.drafts.regenerated
          : fixture.drafts.initial;

        this.draftFieldsByThread.set(threadId, variant.fields);
        assistantText = variant.assistantText;
        yield* this.createDraftStep(variant, fieldsToStream);
      } else {
        assistantText = fixture.conversationReplies.default;
        yield* this.createTextStep(assistantText);
      }

      history.push({
        role: "assistant" as const,
        content: assistantText,
      });
      this.store.save(threadId, history);
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : String(err);
      console.error("Error in mock drafting chat:", errorMessage);
      yield { type: "error", errorText: errorMessage };
    }

    yield {
      type: "finish",
      finishReason: "stop",
      usage: DEFAULT_USAGE,
    };
  }

  reset(threadId?: string): { threadId: string } {
    if (threadId) {
      this.store.delete(threadId);
      this.draftFieldsByThread.delete(threadId);
    }

    return { threadId: randomUUID() };
  }

  save(_body: DraftSavePayload): DraftSaveResult {
    this.nextNodeId += 1;
    const nodeId = String(this.nextNodeId);
    return {
      nodeId,
      previewUrl: `/node/${nodeId}/latest`,
    };
  }

  private async *createTextStep(text: string): AsyncGenerator<StreamEvent> {
    yield { type: "start-step" };
    yield* this.streamText(text);
    yield {
      type: "finish-step",
      finishReason: "stop",
      usage: DEFAULT_USAGE,
      isContinued: false,
    };
  }

  private async *streamText(text: string): AsyncGenerator<StreamEvent> {
    if (!text) {
      return;
    }

    yield { type: "text-start", id: randomUUID() };

    for (const chunk of toTextDeltas(text)) {
      yield { type: "text-delta", textDelta: chunk };
      await delay(SSE_CHUNK_DELAY_MS);
    }

    yield { type: "text-end" };
  }

  private async *createDraftStep(
    variant: DraftFixtureVariant,
    fieldsToStream: string[],
  ): AsyncGenerator<StreamEvent> {
    const selectedFields = selectDraftedFields(variant.fields, fieldsToStream);

    yield { type: "start-step" };
    yield* this.streamText(variant.assistantText);
    await delay(FIELD_GAP_DELAY_MS);

    // Emit orchestration plan to match the Drupal backend flow.
    const fieldNames = Object.keys(selectedFields);
    const plan = [
      { stepId: "main_fields", label: "Main fields", status: "pending" },
    ];
    yield { type: "data-plan", data: plan };
    await delay(SSE_CHUNK_DELAY_MS);

    // Simulate sub-agent progress.
    plan[0].status = "in_progress";
    yield { type: "data-plan", data: [...plan] };
    await delay(FIELD_GAP_DELAY_MS);

    plan[0].status = "done";
    yield { type: "data-plan", data: [...plan] };

    // Emit the drafted fields.
    yield {
      type: "data-drafted-fields",
      data: selectedFields,
    };

    // Confirmation text.
    const confirmText =
      `Draft generated with ${fieldNames.length} fields. ` +
      "Review the content on the right.";
    yield { type: "start-step" };
    yield* this.streamText(confirmText);
    yield {
      type: "finish-step",
      finishReason: "stop",
      usage: DEFAULT_USAGE,
      isContinued: false,
    };
  }

  private async *createSaveStep(
    result: DraftSaveResult,
    confirmation: string,
  ): AsyncGenerator<StreamEvent> {
    const toolCallId = randomUUID();
    yield { type: "start-step" };
    yield {
      type: "tool-call-start",
      id: randomUUID(),
      toolCallId,
      toolName: "save_draft_revision",
    };
    await delay(SSE_CHUNK_DELAY_MS);

    yield { type: "tool-call-end" };
    yield {
      type: "tool-result",
      toolCallId,
      result,
    };
    yield* this.streamText(confirmation);
    yield {
      type: "finish-step",
      finishReason: "stop",
      usage: DEFAULT_USAGE,
      isContinued: false,
    };
  }
}
