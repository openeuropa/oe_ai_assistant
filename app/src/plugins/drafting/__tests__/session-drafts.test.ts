import { describe, expect, it } from "vitest";
import { extractSessionDrafts } from "../session-drafts";

/** Builds a draft_content tool-call part in the assistant-ui shape. */
function draftPart(result: unknown, argsFields?: Record<string, unknown>) {
  return {
    type: "tool-call",
    toolName: "draft_content",
    args: { fields: argsFields ?? {} },
    result,
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

  it("falls back to args fields when a rehydrated result is empty", () => {
    const messages = [{ content: [draftPart({}, { title: "From args" })] }];

    const drafts = extractSessionDrafts(messages);

    expect(drafts).toHaveLength(1);
    expect(drafts[0]?.version).toBeNull();
    expect(drafts[0]?.label).toBe("Draft");
    expect(drafts[0]?.fields).toEqual({ title: "From args" });
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

  it("skips draft calls that produced no fields", () => {
    const messages = [{ content: [draftPart({}, {})] }];

    expect(extractSessionDrafts(messages)).toEqual([]);
  });
});
