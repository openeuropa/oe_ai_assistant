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

  it("keeps the stored call id and leaves a waiting call without a result", () => {
    const [message] = toThreadMessages([
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            id: "call_save",
            type: "function",
            function: { name: "save_draft", arguments: '{"version":2}' },
          },
        ],
      },
    ]);
    if (!message) throw new Error("expected a message");
    const part = (message.content as AnyPart[])[0];
    // The decision names the call, so the stored id has to survive hydration.
    expect(part.toolCallId).toBe("call_save");
    expect(part.args).toEqual({ version: 2 });
    // No result means the call never ran, which is what shows its buttons.
    expect(part.result).toBeUndefined();
  });

  it("drops items that have no content and no tool calls", () => {
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
