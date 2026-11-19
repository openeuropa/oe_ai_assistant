import { describe, expect, it } from "vitest";
import { extractSavedVersions } from "../saved-versions";

/** Builds a drafting_save_draft tool-call part, with an optional result. */
function savePart(result?: unknown) {
  return { type: "tool-call", toolName: "drafting_save_draft", result };
}

/** A saved result for the given version, as the reloaded transcript returns it. */
function saved(version: number) {
  return { version, nodeId: String(version), previewUrl: "/node/1/latest" };
}

describe("extractSavedVersions", () => {
  it("returns an empty set for an empty thread", () => {
    expect(extractSavedVersions([])).toEqual(new Set());
  });

  it("collects the versions of saved results, reloaded or live", () => {
    const messages = [
      { content: [savePart(saved(2))] },
      { content: [savePart(JSON.stringify(saved(1)))] },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set([1, 2]));
  });

  it("ignores failed, cancelled and unanswered saves, other tools and save chips", () => {
    const messages = [
      { content: [savePart({ version: 3, error: "No such draft." })] },
      { content: [savePart([])] },
      { content: [savePart()] },
      {
        content: [
          {
            type: "tool-call",
            toolName: "drafting_draft_content",
            result: saved(5),
          },
          {
            type: "tool-call",
            toolName: "editorial_event",
            args: { eventType: "save", version: 4 },
          },
        ],
      },
    ];
    expect(extractSavedVersions(messages)).toEqual(new Set());
  });
});
