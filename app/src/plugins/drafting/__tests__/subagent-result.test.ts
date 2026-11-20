import { describe, expect, it } from "vitest";
import { parseSubagentResult, splitReport } from "../subagent-result";

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
        verified: true,
        label: "Briefing note.pdf · p.2",
      },
      { type: "text", text: ". Costs rose " },
      {
        type: "citation",
        documentId: "2",
        page: 5,
        verified: false,
        label: "Document 2 · p.5",
      },
      { type: "text", text: " a lot " },
      {
        type: "citation",
        documentId: "7",
        page: 1,
        verified: false,
        label: "Document 7 · p.1",
      },
      { type: "text", text: "." },
    ]);
  });

  it("returns the report whole when it has no marker", () => {
    expect(splitReport("Plain.", [], {})).toEqual([
      { type: "text", text: "Plain." },
    ]);
    expect(splitReport("", [], {})).toEqual([]);
  });
});
