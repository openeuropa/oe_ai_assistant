import { describe, expect, it } from "vitest";
import { extractWaitingSaves } from "../waiting-saves";

/** One save tool-call part, with or without a result. */
function savePart(
  callId: string,
  version: number,
  result?: unknown,
): Record<string, unknown> {
  return {
    type: "tool-call",
    toolName: "drafting_save_draft",
    toolCallId: callId,
    args: { version, awaitingDecision: result === undefined },
    ...(result === undefined ? {} : { result }),
  };
}

describe("extractWaitingSaves", () => {
  it("lists a save nothing has answered", () => {
    expect(extractWaitingSaves([{ content: [savePart("call_a", 1)] }])).toEqual(
      [{ callId: "call_a", version: 1 }],
    );
  });

  it("drops a save once a later message answers it", () => {
    const messages = [
      { content: [savePart("call_a", 1)] },
      { content: [savePart("call_a", 1, { nodeId: "7", version: 1 })] },
    ];

    expect(extractWaitingSaves(messages)).toEqual([]);
  });

  it("lists both saves of a reply that made two", () => {
    expect(
      extractWaitingSaves([
        { content: [savePart("call_a", 1), savePart("call_b", 2)] },
      ]),
    ).toEqual([
      { callId: "call_a", version: 1 },
      { callId: "call_b", version: 2 },
    ]);
  });

  it("ignores the tool calls of other tools", () => {
    expect(
      extractWaitingSaves([
        {
          content: [{ type: "tool-call", toolName: "drafting_draft_content" }],
        },
      ]),
    ).toEqual([]);
  });
});
