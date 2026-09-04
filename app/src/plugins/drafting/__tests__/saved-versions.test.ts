import { describe, expect, it } from "vitest";
import { extractSavedVersions } from "../saved-versions";

/** Builds an editorial_event tool-call part in the assistant-ui shape. */
function eventPart(args: Record<string, unknown>) {
  return { type: "tool-call", toolName: "editorial_event", args };
}

describe("extractSavedVersions", () => {
  it("returns an empty set for an empty thread", () => {
    expect(extractSavedVersions([])).toEqual(new Set());
  });

  it("collects the versions named by save events", () => {
    const messages = [
      { content: [eventPart({ eventType: "save", version: 2 })] },
      { content: [eventPart({ eventType: "save", version: 1 })] },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set([1, 2]));
  });

  it("ignores other events, non-numeric versions and other tools", () => {
    const messages = [
      { content: [eventPart({ eventType: "tone", version: 3 })] },
      { content: [eventPart({ eventType: "save", version: "4" })] },
      { content: [eventPart({ eventType: "save" })] },
      {
        content: [
          {
            type: "tool-call",
            toolName: "draft_content",
            args: { version: 5 },
          },
        ],
      },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set());
  });
});
