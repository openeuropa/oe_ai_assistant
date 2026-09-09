/**
 * Unit tests for the thread-events module.
 *
 * Covers the message shape contract (buildEventThreadMessage must produce
 * the same editorial_event tool-call part that hydration emits, because the
 * chip renderer consumes both) and the appendEventToThread splice, which is
 * exercised against a fake thread exposing export/import.
 */

import type { ExportedMessageRepository } from "@assistant-ui/react";
import { describe, expect, it } from "vitest";
import { appendEventToThread, buildEventThreadMessage } from "../thread-events";

// biome-ignore lint/suspicious/noExplicitAny: testing the raw part union.
type AnyPart = any;

describe("buildEventThreadMessage", () => {
  it("returns an assistant message with one editorial_event tool-call part", () => {
    const msg = buildEventThreadMessage({
      eventType: "tone",
      summary: "Tone changed to Formal",
    });

    expect(msg.role).toBe("assistant");

    const content = msg.content as AnyPart[];
    expect(content).toHaveLength(1);

    const part = content[0];
    expect(part.type).toBe("tool-call");
    expect(part.toolName).toBe("editorial_event");
    expect(part.args).toEqual({
      eventType: "tone",
      summary: "Tone changed to Formal",
    });
    expect(part.result).toEqual({});
  });

  it("produces distinct toolCallIds on successive calls", () => {
    const saved = buildEventThreadMessage({
      eventType: "save",
      summary: "Draft 3 saved",
      version: 3,
    });
    const savedPart = (saved.content as AnyPart[])[0];
    expect(savedPart?.args).toEqual({
      eventType: "save",
      summary: "Draft 3 saved",
      version: 3,
    });

    const first = buildEventThreadMessage({ eventType: "tone", summary: "A" });
    const second = buildEventThreadMessage({ eventType: "tone", summary: "B" });

    const id1 = (first.content as AnyPart[])[0].toolCallId as string;
    const id2 = (second.content as AnyPart[])[0].toolCallId as string;

    expect(id1).not.toBe(id2);
    expect(id1.startsWith("event-local-")).toBe(true);
    expect(id2.startsWith("event-local-")).toBe(true);
  });
});

describe("appendEventToThread", () => {
  /** Builds a fake thread around a fixed exported repository. */
  function fakeThread(exported: ExportedMessageRepository) {
    let imported: ExportedMessageRepository | null = null;
    return {
      export: () => exported,
      import: (repo: ExportedMessageRepository) => {
        imported = repo;
      },
      get imported(): ExportedMessageRepository | null {
        return imported;
      },
    };
  }

  it("preserves existing messages and parents the event onto the head", () => {
    // Two-message repository where the head is the assistant reply.
    const exported = {
      headId: "m2",
      messages: [
        { message: { id: "m1" }, parentId: null },
        { message: { id: "m2" }, parentId: "m1" },
      ],
    } as unknown as ExportedMessageRepository;
    const thread = fakeThread(exported);

    appendEventToThread(thread, { eventType: "tone", summary: "A" });

    const repo = thread.imported as ExportedMessageRepository;
    // Existing entries pass through untouched, keeping their parentId links.
    expect(repo.messages.slice(0, 2)).toEqual(exported.messages);

    // The event is appended, parented onto the head, and becomes the head.
    const eventEntry = repo.messages[2] as AnyPart;
    expect(eventEntry.parentId).toBe("m2");
    expect(repo.headId).toBe(eventEntry.message.id);

    // The appended message carries the normalized editorial_event part.
    const part = eventEntry.message.content[0];
    expect(part.toolName).toBe("editorial_event");
    expect(part.args).toEqual({ eventType: "tone", summary: "A" });
  });

  it("appends with a null parent on an empty thread", () => {
    const thread = fakeThread({ headId: null, messages: [] });

    appendEventToThread(thread, { eventType: "template", summary: "B" });

    const repo = thread.imported as ExportedMessageRepository;
    expect(repo.messages).toHaveLength(1);
    const eventEntry = repo.messages[0] as AnyPart;
    expect(eventEntry.parentId).toBeNull();
    expect(repo.headId).toBe(eventEntry.message.id);
  });
});
