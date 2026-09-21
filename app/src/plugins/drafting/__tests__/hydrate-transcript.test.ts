import { describe, expect, it } from "vitest";
import type { SessionMessage } from "@/api/session-messages";
import { toThreadMessages } from "../hydrate-transcript";

// biome-ignore lint/suspicious/noExplicitAny: reading the seeded part union.
type AnyPart = any;

describe("toThreadMessages", () => {
  it("maps text turns to text parts", () => {
    const input: SessionMessage[] = [
      { role: "user", content: "Draft a news article." },
      { role: "assistant", content: "Here is a draft." },
    ];

    const result = toThreadMessages(input);

    expect(result).toHaveLength(2);
    const [first] = result;
    if (!first) throw new Error("expected a message");
    expect(first.role).toBe("user");
    expect(first.content).toEqual([
      { type: "text", text: "Draft a news article." },
    ]);
  });

  it("maps a draft_group tool call to a part with its group and result", () => {
    const result = {
      group: "main_fields",
      label: "Main fields",
      fields: { title: [{ value: "Test Title" }] },
      pending: [],
      draft: { version: 1, context: null, fields: { title: [] } },
    };
    const input: SessionMessage[] = [
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            type: "function",
            function: {
              name: "draft_group",
              arguments: '{"group":"main_fields"}',
            },
            result,
          },
        ],
      },
    ];

    const [message] = toThreadMessages(input);
    if (!message) throw new Error("expected a message");
    const part = (message.content as AnyPart[])[0];
    expect(part.type).toBe("tool-call");
    expect(part.toolName).toBe("draft_group");
    expect(part.args).toEqual({ group: "main_fields" });
    // The raw result travels as-is so the tool UI can read the draft.
    expect(part.result).toEqual(result);
  });

  it("maps an event item to an editorial_event tool-call part", () => {
    const input: SessionMessage[] = [
      {
        role: "event",
        type: "tone",
        summary: "Tone set to Formal.",
        at: "2026-08-12T10:00:00Z",
      },
    ];

    const result = toThreadMessages(input);

    expect(result).toHaveLength(1);
    const [message] = result;
    if (!message) throw new Error("expected a message");
    // Events are surfaced as assistant messages so they appear in the thread.
    expect(message.role).toBe("assistant");
    const part = (message.content as AnyPart[])[0];
    expect(part.type).toBe("tool-call");
    expect(part.toolCallId).toBe("event-0");
    expect(part.toolName).toBe("editorial_event");
    expect(part.args).toEqual({
      eventType: "tone",
      summary: "Tone set to Formal.",
      at: "2026-08-12T10:00:00Z",
    });
    expect(part.result).toEqual({});
  });

  it("maps a get_draft_history tool call to a tool-call part with parsed args", () => {
    const args = { sessionId: "abc-123" };
    const historyResult = { drafts: [] };
    const input: SessionMessage[] = [
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            type: "function",
            function: {
              name: "get_draft_history",
              arguments: JSON.stringify(args),
            },
            result: historyResult,
          },
        ],
      },
    ];

    const result = toThreadMessages(input);

    expect(result).toHaveLength(1);
    const [message] = result;
    if (!message) throw new Error("expected a message");
    const part = (message.content as AnyPart[])[0];
    expect(part.type).toBe("tool-call");
    expect(part.toolName).toBe("get_draft_history");
    // Arguments JSON must be safe-parsed into an object.
    expect(part.args).toEqual(args);
    expect(part.result).toEqual(historyResult);
  });

  it("safe-parses invalid arguments JSON to an empty object", () => {
    const input: SessionMessage[] = [
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            type: "function",
            function: { name: "some_tool", arguments: "NOT JSON" },
          },
        ],
      },
    ];

    const result = toThreadMessages(input);

    expect(result).toHaveLength(1);
    const [message] = result;
    if (!message) throw new Error("expected a message");
    const part = (message.content as AnyPart[])[0];
    expect(part.args).toEqual({});
    expect(part.result).toEqual({});
  });

  it("carries the saved version on save event parts", () => {
    const [message] = toThreadMessages([
      { role: "event", type: "save", summary: "Draft 2 saved", version: 2 },
    ]);
    if (!message) throw new Error("expected a message");
    const part = (message.content as AnyPart[])[0];
    expect(part.args).toMatchObject({ eventType: "save", version: 2 });
  });

  it("drops items that have no content, no tool calls, and are not events", () => {
    // An assistant item with empty content and no tool calls produces nothing.
    const input: SessionMessage[] = [
      {
        role: "assistant",
        content: "",
      },
    ];

    expect(toThreadMessages(input)).toEqual([]);
  });

  it("carries the author name and id into the message metadata", () => {
    const input: SessionMessage[] = [
      {
        role: "user",
        content: "Rework the headline.",
        userName: "Maria Rossi",
        userId: "2",
      },
    ];

    const [message] = toThreadMessages(input);
    if (!message) throw new Error("expected a message");
    expect((message as AnyPart).metadata).toEqual({
      custom: { userName: "Maria Rossi", userId: "2" },
    });
  });
});
