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
import type {
  ConversationStore,
  TranscriptMessage,
} from "./conversation-store";
import type {
  ChatOptions,
  DraftingService,
  StreamEvent,
} from "./drafting-service";
import { emitDraftToolCall, extractFieldsToStream } from "./drafting-service";

interface DraftFixtureVariant {
  assistantText: string;
  fields: Record<string, unknown>;
}

interface BundleDraftingFixture {
  bundle: string;
  conversationReplies: {
    default: string;
  };
  drafts: {
    initial: DraftFixtureVariant;
    regenerated: DraftFixtureVariant;
  };
}

const DEFAULT_USAGE = { inputTokens: 0, outputTokens: 0 };
/** The call id of the mock's gated save; only one ever waits at a time. */
const SAVE_CALL_ID = "mock-save-call";
const DRAFT_INTENT_PATTERN =
  /\b(draft|generate|write|create|prepare|regenerate|rewrite|revise|update)\b/i;
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

/**
 * Matches the message the Save button sends, "Save draft N." or "Save draft
 * N.M.".
 *
 * That message contains "draft", so DRAFT_INTENT_PATTERN matches it too.
 * Without this check first, the mock would write a new version instead of
 * saving the one the editor is looking at.
 */
const SAVE_INTENT_PATTERN = /^\s*save draft (\d+(?:\.\d+)?)/i;

/** True when the prompt should trigger a mock draft fixture. */
function isDraftRequest(message: string, fieldsToStream: string[]): boolean {
  if (SAVE_INTENT_PATTERN.test(message)) {
    return false;
  }

  return fieldsToStream.length > 0 || DRAFT_INTENT_PATTERN.test(message);
}

/** The draft version a save request names, or null for another prompt. */
export function saveRequestVersion(message: string): string | null {
  const match = message.match(SAVE_INTENT_PATTERN);

  return match?.[1] ?? null;
}

/**
 * Drops a zero minor from a version, so "1.0" shows as "1".
 *
 * Mirrors DraftVersion::display() on the backend and displayDraftVersion()
 * in the app. Nothing under server/ imports from src/, so the mock keeps
 * its own copy.
 */
function displayVersion(version: string): string {
  return version.endsWith(".0") ? version.slice(0, -2) : version;
}

export class MockDraftingService implements DraftingService {
  private nextNodeId = 42000;
  /**
   * Draft history per session: fields keyed by version, mirroring the
   * Drupal backend's drafting_draft_content result history. Its size numbers
   * the next draft version.
   */
  private readonly draftsBySession = new Map<
    string,
    Map<string, Record<string, unknown>>
  >();
  /** The version a waiting save call named, until the editor decides it. */
  private pendingSaveVersion: string | null = null;

  constructor(private readonly store: ConversationStore) {}

  async *chat(opts: ChatOptions): AsyncGenerator<StreamEvent> {
    const { message, sessionId, bundle, approval } = opts;
    const messageId = randomUUID();
    const fieldsToStream = extractFieldsToStream(message);

    yield { type: "start", messageId };

    try {
      const history = this.store.load(sessionId);
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
      } else if (approval) {
        // A decision arrives as a turn of its own, and answers the call
        // it names. No model turn is spent on the outcome, as on the site.
        assistantText = "";
        yield* this.createSaveResultStep(sessionId, approval);
      } else if (saveRequestVersion(message) !== null) {
        assistantText = "";
        yield* this.createSaveRequestStep(
          saveRequestVersion(message) as string,
        );
      } else if (isDraftRequest(message, fieldsToStream)) {
        const useRegeneratedVariant =
          fieldsToStream.length > 0 ||
          /\b(regenerate|rewrite|revise|update)\b/i.test(message);
        const variant = useRegeneratedVariant
          ? fixture.drafts.regenerated
          : fixture.drafts.initial;

        // Record the draft under the next version.
        const versions =
          this.draftsBySession.get(sessionId) ??
          new Map<string, Record<string, unknown>>();
        const version = `${versions.size + 1}.0`;
        versions.set(version, variant.fields);
        this.draftsBySession.set(sessionId, versions);

        assistantText = variant.assistantText;
        yield* this.createDraftStep(variant, fieldsToStream, version);
      } else {
        assistantText = fixture.conversationReplies.default;
        yield* this.createTextStep(assistantText);
      }

      history.push({
        role: "assistant" as const,
        content: assistantText,
      });
      this.store.save(sessionId, history);
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

  reset(sessionId: string): { status: string } {
    this.store.delete(sessionId);
    this.draftsBySession.delete(sessionId);
    return { status: "ok" };
  }

  getMessages(sessionId: string): TranscriptMessage[] {
    return this.store.getTranscript(sessionId);
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

  /**
   * Emits a save call that waits, the way the site's gated tool does.
   *
   * The call carries no result, which is what makes the card in the thread
   * ask the editor to confirm it. The decision turn carries only the call
   * ID, so remember the version here; on the site Neuron re-runs
   * SaveDraftNeuronTool with the call's own arguments instead.
   */
  private async *createSaveRequestStep(
    version: string,
  ): AsyncGenerator<StreamEvent> {
    this.pendingSaveVersion = version;
    yield { type: "start-step" };
    yield {
      type: "tool-call-start",
      id: SAVE_CALL_ID,
      toolCallId: SAVE_CALL_ID,
      toolName: "drafting_save_draft",
    };
    yield { type: "tool-call-delta", argsText: JSON.stringify({ version }) };
    yield { type: "tool-call-end" };
    yield {
      type: "finish-step",
      finishReason: "stop",
      usage: DEFAULT_USAGE,
      isContinued: false,
    };
  }

  /** Answers the call the editor decided, and says what came of it. */
  private async *createSaveResultStep(
    sessionId: string,
    approval: { callId: string; decision: "approve" | "reject" },
  ): AsyncGenerator<StreamEvent> {
    const version = this.pendingSaveVersion ?? "";
    this.pendingSaveVersion = null;
    const fields = this.draftsBySession.get(sessionId)?.get(version);

    let result: Record<string, unknown>;
    if (approval.decision !== "approve") {
      result = { cancelled: true };
    } else if (!fields) {
      result = {
        version,
        error: `Draft ${displayVersion(version)} does not exist in this session.`,
      };
    } else {
      this.nextNodeId += 1;
      const nodeId = String(this.nextNodeId);
      result = {
        version,
        nodeId,
        previewUrl: `/node/${nodeId}/latest`,
        draftName: `Draft ${displayVersion(version)}`,
      };
    }

    yield { type: "tool-result", toolCallId: approval.callId, result };
  }

  private async *createDraftStep(
    variant: DraftFixtureVariant,
    fieldsToStream: string[],
    version: string,
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

    // Emit the drafted fields in the versioned shape the Drupal backend
    // sends. parseDraftResult() reads a payload with no numeric version as
    // a legacy flat fields map and returns version null, so the rail would
    // list a "Draft null" entry next to the real draft.
    yield {
      type: "data-drafted-fields",
      data: {
        version,
        status: "drafted",
        context: null,
        groups: [],
        error: "",
        fields: selectedFields,
        createdAt: new Date().toISOString(),
      },
    };

    // Emit the drafting_draft_content tool call so the clickable card appears
    // inline, with the versioned result shape the Drupal backend uses.
    yield* emitDraftToolCall({
      version,
      context: null,
      fields: selectedFields,
    });

    // Confirmation text, matching the Drupal backend wording.
    const confirmText =
      `Draft ${displayVersion(version)} generated with ${fieldNames.length} fields. ` +
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
}
