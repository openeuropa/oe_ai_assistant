import { describe, expect, it } from "vitest";
import { extractSessionDrafts } from "../session-drafts";

/** Builds a drafting_draft_content tool-call part in the assistant-ui shape. */
function draftPart(result: unknown, argsFields?: Record<string, unknown>) {
  return {
    type: "tool-call",
    toolName: "drafting_draft_content",
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

  it("merges a live draft not yet reflected in the thread", () => {
    // The tool-call part's own result is the bare JSON string the backend
    // returns to the LLM ({"draft":"Draft 1","version":1}) — not an
    // object, and never carrying the fields (the backend keeps the tool
    // result small so the LLM never replays a whole content type). A
    // draft produced live surfaces only through the channel's
    // data-drafted-fields event, until a reload rehydrates the thread
    // with the join getMessages() performs server side.
    const messages = [
      { content: [draftPart('{"draft":"Draft 1","version":1}')] },
    ];
    const liveDrafts = {
      1: { version: 1, context: null, fields: { title: "Live" } },
    };

    const drafts = extractSessionDrafts(messages, liveDrafts);

    expect(drafts).toHaveLength(1);
    expect(drafts[0]?.version).toBe(1);
    expect(drafts[0]?.label).toBe("Draft 1");
    expect(drafts[0]?.fields).toEqual({ title: "Live" });
  });

  it("prefers the thread's own draft over a live one of the same version", () => {
    const messages = [
      {
        content: [
          draftPart({
            version: 1,
            context: { tone: null, template: null, documents: [] },
            fields: { title: "From thread" },
          }),
        ],
      },
    ];
    const liveDrafts = {
      1: { version: 1, context: null, fields: { title: "Live" } },
    };

    const drafts = extractSessionDrafts(messages, liveDrafts);

    expect(drafts).toHaveLength(1);
    expect(drafts[0]?.fields).toEqual({ title: "From thread" });
  });

  it("keeps an earlier live draft once a later one streams in", () => {
    const liveDrafts = {
      1: { version: 1, context: null, fields: { title: "First" } },
      2: { version: 2, context: null, fields: { title: "Second" } },
    };

    const drafts = extractSessionDrafts([], liveDrafts);

    expect(drafts.map((d) => d.version)).toEqual([1, 2]);
  });

  it("ignores a live draft that has not produced fields yet", () => {
    const liveDrafts = { 1: { version: 1, context: null, fields: {} } };

    expect(extractSessionDrafts([], liveDrafts)).toEqual([]);
  });
});
