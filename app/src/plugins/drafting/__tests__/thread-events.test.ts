/**
 * Unit tests for the thread-events module.
 *
 * Covers the message shape contract only: buildEventThreadMessage must
 * produce the same editorial_event tool-call part that hydration emits,
 * because the chip renderer consumes both. The appendEventToThread splice
 * delegates to assistant-ui and is exercised in the browser, not here.
 */

import { describe, expect, it } from "vitest";
import { buildEventThreadMessage } from "../thread-events";

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
    const first = buildEventThreadMessage({ eventType: "tone", summary: "A" });
    const second = buildEventThreadMessage({ eventType: "tone", summary: "B" });

    const id1 = (first.content as AnyPart[])[0].toolCallId as string;
    const id2 = (second.content as AnyPart[])[0].toolCallId as string;

    expect(id1).not.toBe(id2);
    expect(id1.startsWith("event-local-")).toBe(true);
    expect(id2.startsWith("event-local-")).toBe(true);
  });
});
