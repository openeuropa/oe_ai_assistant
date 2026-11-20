import { describe, expect, it } from "vitest";
import {
  expandPages,
  hasCitations,
  pagesLabel,
  parseSubagentResult,
  splitCitations,
  splitReport,
} from "../subagent-result";

describe("parseSubagentResult", () => {
  it("reads citations and the joined ledger state", () => {
    const parsed = parseSubagentResult({
      task_id: "sub_1",
      subagent: "researcher",
      status: "completed",
      report: "Text",
      citations: [
        { document_id: 1, page: 2, verified: true },
        { document_id: "3", page: 4 },
        { page: 1 },
      ],
      live: { status: "completed", deliveryState: "delivered" },
    });

    expect(parsed?.citations).toEqual([
      { documentId: "1", page: 2, verified: true },
      { documentId: "3", page: 4, verified: false },
    ]);
    expect(parsed?.live).toEqual({
      status: "completed",
      deliveryState: "delivered",
    });
  });

  it("reads the JSON text a streamed result arrives as", () => {
    const parsed = parseSubagentResult(
      JSON.stringify({
        task_id: "sub_1",
        status: "needs_input",
        question: "Which year?",
      }),
    );

    expect(parsed?.status).toBe("needs_input");
    expect(parsed?.question).toBe("Which year?");
    expect(parseSubagentResult("not json")).toBeNull();
  });

  it("returns null for a value that names no task", () => {
    expect(parseSubagentResult({ status: "completed" })).toBeNull();
  });
});

describe("parseSubagentResult of a tool error", () => {
  it("reads the tool error handler's answer as a failed result", () => {
    const parsed = parseSubagentResult(
      JSON.stringify({ tool: "research_documents", error: "The tool failed." }),
    );

    expect(parsed?.status).toBe("failed");
    expect(parsed?.error).toBe("The tool failed.");
    expect(parsed?.taskId).toBe("");
  });
});

describe("expandPages", () => {
  it("expands ranges and lists, and caps a range at 20 pages", () => {
    expect(expandPages("4")).toEqual([4]);
    expect(expandPages("4–6")).toEqual([4, 5, 6]);
    expect(expandPages("4-5")).toEqual([4, 5]);
    expect(expandPages("4, p.9")).toEqual([4, 9]);
    expect(expandPages("4, 7-8")).toEqual([4, 7, 8]);
    expect(expandPages("1-90")).toHaveLength(20);
  });

  it("writes pages as a chip label", () => {
    expect(pagesLabel([4])).toBe("p.4");
    expect(pagesLabel([4, 5])).toBe("p.4–5");
    expect(pagesLabel([4, 5, 9])).toBe("p.4–5, 9");
  });
});

describe("splitReport", () => {
  const citations = [
    { documentId: "1", page: 2, verified: true },
    { documentId: "2", page: 5, verified: false },
  ];
  const titles = { "1": "Briefing note.pdf" };

  it("splits markers out of the text", () => {
    const tokens = splitReport(
      "Funding is 5M [doc:1 p.2]. Costs rose [doc:2 p.5] a lot [doc:7 p.1].",
      citations,
      titles,
    );

    expect(tokens).toEqual([
      { type: "text", text: "Funding is 5M " },
      {
        type: "citation",
        documentId: "1",
        page: 2,
        pages: [2],
        verified: true,
        partial: false,
        status: "verified",
        label: "Briefing note.pdf · p.2",
      },
      { type: "text", text: ". Costs rose " },
      {
        type: "citation",
        documentId: "2",
        page: 5,
        pages: [5],
        verified: false,
        partial: false,
        status: "unverified",
        label: "Document 2 · p.5",
      },
      { type: "text", text: " a lot " },
      {
        type: "citation",
        documentId: "7",
        page: 1,
        pages: [1],
        verified: false,
        partial: false,
        status: "unverified",
        label: "Document 7 · p.1",
      },
      { type: "text", text: "." },
    ]);
  });

  it("draws a range or a list as one chip", () => {
    const all = [
      { documentId: "1", page: 4, verified: true },
      { documentId: "1", page: 5, verified: true },
      { documentId: "1", page: 9, verified: false },
    ];
    for (const marker of [
      "[doc:1 p.4–5]",
      "[doc:1 p.4-5]",
      "[doc:1 pp.4–5]",
      "[doc:1 p.4, p.5]",
      "[doc:1 p.4, 5]",
    ]) {
      const tokens = splitReport(`A ${marker}.`, all, titles);
      expect(tokens).toHaveLength(3);
      expect(tokens[1]).toMatchObject({
        type: "citation",
        pages: [4, 5],
        status: "verified",
        label: "Briefing note.pdf · p.4–5",
      });
    }
  });

  it("marks a chip partly verified when only some pages are", () => {
    const tokens = splitReport(
      "[doc:1 p.4, 9]",
      [
        { documentId: "1", page: 4, verified: true },
        { documentId: "1", page: 9, verified: false },
      ],
      titles,
    );

    expect(tokens[0]).toMatchObject({
      status: "partial",
      verified: false,
      partial: true,
      label: "Briefing note.pdf · p.4, 9",
    });
  });

  it("returns the report whole when it has no marker", () => {
    expect(splitReport("Plain.", [], {})).toEqual([
      { type: "text", text: "Plain." },
    ]);
    expect(splitReport("", [], {})).toEqual([]);
  });
});

describe("splitCitations", () => {
  it("draws the markers of an editorial reply as neutral chips", () => {
    const tokens = splitCitations("It grew [doc:1 p.2–3].", {
      "1": "Briefing note.pdf",
    });

    expect(hasCitations("It grew [doc:1 p.2–3].")).toBe(true);
    expect(hasCitations("No marker [doc:x].")).toBe(false);
    expect(tokens).toHaveLength(3);
    expect(tokens[1]).toMatchObject({
      type: "citation",
      status: "neutral",
      pages: [2, 3],
      label: "Briefing note.pdf · p.2–3",
    });
  });
});
