import { describe, expect, it } from "vitest";
import { extractSessionDrafts } from "../session-drafts";

/** Builds a completing draft_group tool-call part in the assistant-ui shape. */
function draftPart(draft: unknown) {
  return {
    type: "tool-call",
    toolName: "draft_group",
    args: { group: "main_fields" },
    result: { group: "main_fields", fields: {}, pending: [], draft },
  };
}

/** Builds a revise_draft tool-call part in the assistant-ui shape. */
function revisePart(draft: unknown) {
  return {
    type: "tool-call",
    toolName: "revise_draft",
    args: { groups: ["main_fields"] },
    result: { revised: ["main_fields"], draft },
  };
}

/** Builds a stored draft, optionally revising another version. */
function draft(version: number, revisionOf: number | null = null) {
  return {
    version,
    revisionOf,
    context: { tone: null, template: null, documents: [] },
    fields: { title: `v${version}` },
  };
}

describe("extractSessionDrafts", () => {
  it("returns an empty list for an empty thread", () => {
    expect(extractSessionDrafts([])).toEqual([]);
  });

  it("numbers revisions under the draft they revise", () => {
    // A revision of the first draft, produced after the second one.
    const messages = [
      { content: [{ type: "text" }] },
      { content: [draftPart(draft(1))] },
      { content: [draftPart(draft(2))] },
      { content: [revisePart(draft(3, 1))] },
    ];

    const drafts = extractSessionDrafts(messages);

    expect(drafts.map((d) => d.label)).toEqual(["1.0", "1.1", "2.0"]);
    expect(drafts.map((d) => d.name)).toEqual([
      "Draft 1.0",
      "Draft 1.1",
      "Draft 2.0",
    ]);
    // The revision sorts under the draft it revises, not by creation.
    expect(drafts.map((d) => d.version)).toEqual([1, 3, 2]);
    expect(drafts[1]?.fields).toEqual({ title: "v3" });
  });

  it("carries the creation time of the message holding the draft", () => {
    const createdAt = new Date(2026, 4, 22, 14, 30);
    const versioned = {
      version: 1,
      context: { tone: null, template: null, documents: [] },
      fields: { title: "Timed" },
    };
    const messages = [
      { content: [draftPart(versioned)], createdAt },
      { content: [draftPart({ ...versioned, version: 2 })] },
    ];

    const drafts = extractSessionDrafts(messages);

    expect(drafts[0]?.createdAt).toBe(createdAt);
    expect(drafts[1]?.createdAt).toBeNull();
  });

  it("ignores non-draft tool calls and text parts", () => {
    const messages = [
      {
        content: [
          { type: "text" },
          { type: "tool-call", toolName: "save_draft_revision", result: {} },
          {
            type: "tool-call",
            toolName: "editorial_event",
            args: { eventType: "tone", summary: "Tone changed" },
            result: {},
          },
        ],
      },
    ];

    expect(extractSessionDrafts(messages)).toEqual([]);
  });

  it("skips group calls that carry no draft or an empty one", () => {
    const messages = [
      {
        content: [
          {
            type: "tool-call",
            toolName: "draft_group",
            args: { group: "main_fields" },
            result: { group: "main_fields", fields: { title: "x" } },
          },
          draftPart({ version: 1, context: null, fields: {} }),
        ],
      },
    ];

    expect(extractSessionDrafts(messages)).toEqual([]);
  });
});
