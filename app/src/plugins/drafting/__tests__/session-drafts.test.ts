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

describe("extractSessionDrafts", () => {
  it("returns an empty list for an empty thread", () => {
    expect(extractSessionDrafts([])).toEqual([]);
  });

  it("collects versioned drafts in version order with their labels", () => {
    const messages = [
      { content: [{ type: "text" }] },
      {
        content: [
          draftPart({
            version: 2,
            context: { tone: null, template: null, documents: [] },
            fields: { title: "Second" },
          }),
        ],
      },
      {
        content: [
          draftPart({
            version: 1,
            context: { tone: null, template: null, documents: [] },
            fields: { title: "First" },
          }),
        ],
      },
    ];

    const drafts = extractSessionDrafts(messages);

    expect(drafts.map((d) => d.label)).toEqual(["Draft 1", "Draft 2"]);
    expect(drafts[0]?.fields).toEqual({ title: "First" });
    expect(drafts[1]?.version).toBe(2);
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
