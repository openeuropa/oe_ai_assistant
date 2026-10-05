import { describe, expect, it } from "vitest";
import { extractSavedVersions } from "../saved-versions";

/** Builds a save_draft tool-call part in the assistant-ui shape. */
function savePart(result?: Record<string, unknown>) {
  return {
    type: "tool-call",
    toolName: "save_draft",
    ...(result === undefined ? {} : { result }),
  };
}

describe("extractSavedVersions", () => {
  it("returns an empty set for an empty thread", () => {
    expect(extractSavedVersions([])).toEqual(new Set());
  });

  it("collects the versions a save wrote", () => {
    const messages = [
      { content: [savePart({ version: 2, nodeId: "7" })] },
      { content: [savePart({ version: 1, nodeId: "7" })] },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set([1, 2]));
  });

  it("reads a result the stream sent as text", () => {
    const messages = [
      {
        content: [
          {
            type: "tool-call",
            toolName: "save_draft",
            result: '{"version":3,"nodeId":"7"}',
          },
        ],
      },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set([3]));
  });

  it("ignores waiting calls, refusals and other tools", () => {
    const messages = [
      // Still waiting for the editor's decision.
      { content: [savePart()] },
      // Refused, so nothing was written.
      { content: [savePart({ version: 3, error: "Access denied." })] },
      // A node without a version says nothing about which draft it was.
      { content: [savePart({ nodeId: "7" })] },
      {
        content: [
          {
            type: "tool-call",
            toolName: "draft_group",
            result: { version: 5, nodeId: "7" },
          },
        ],
      },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set());
  });
});
