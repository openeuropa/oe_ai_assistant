import { describe, expect, it } from "vitest";
import { toThreadMessages } from "../hydrate-transcript";
import type { DraftingMessage } from "../types";

// biome-ignore lint/suspicious/noExplicitAny: reading the seeded part union.
type AnyPart = any;

describe("toThreadMessages", () => {
  it("maps text turns to text parts", () => {
    const input: DraftingMessage[] = [
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

  it("maps a draft_content tool call to a clickable tool-call part", () => {
    const fields = { title: [{ value: "Test Title" }] };
    const input: DraftingMessage[] = [
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            type: "function",
            function: { name: "draft_content", arguments: "{}" },
            result: fields,
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
    expect(part.toolName).toBe("draft_content");
    expect(part.args).toEqual({ fields });
    expect(part.result).toEqual(fields);
  });

  it("ignores non-draft tool calls and drops empty turns", () => {
    const input: DraftingMessage[] = [
      {
        role: "assistant",
        content: "",
        toolCalls: [
          {
            type: "function",
            function: { name: "get_content_schema", arguments: "{}" },
          },
        ],
      },
    ];

    expect(toThreadMessages(input)).toEqual([]);
  });
});
