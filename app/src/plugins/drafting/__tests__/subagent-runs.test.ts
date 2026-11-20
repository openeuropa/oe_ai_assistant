import { describe, expect, it } from "vitest";
import {
  applySubagentEvent,
  humanizeToolName,
  type SubagentRuns,
  summarizeStepInput,
} from "../subagent-runs";

const STARTED = { callId: "call_1", taskId: "sub_1", subagent: "researcher" };

/** The data of an activity frame: {id, type, data}. */
function activity(id: string, data: Record<string, unknown>) {
  return {
    id,
    type: "subagent",
    data: { callId: "call_1", taskId: "sub_1", tool: "search", ...data },
  };
}

describe("applySubagentEvent", () => {
  it("starts a run for the call", () => {
    const runs = applySubagentEvent({}, "subagent-started", STARTED);

    expect(runs["call_1"]).toEqual({
      taskId: "sub_1",
      subagent: "researcher",
      status: "running",
      steps: [],
      text: "",
    });
  });

  it("starts a queued run for a background call", () => {
    const runs = applySubagentEvent({}, "subagent-queued", STARTED);

    expect(runs["call_1"]?.status).toBe("queued");
  });

  it("updates a step in place by activity id", () => {
    let runs: SubagentRuns = applySubagentEvent(
      {},
      "subagent-started",
      STARTED,
    );
    runs = applySubagentEvent(
      runs,
      "workflow-activity",
      activity("subagent:sub_1.a", {
        input: '{"query":"funding"}',
        status: "running",
      }),
    );
    runs = applySubagentEvent(
      runs,
      "workflow-activity",
      activity("subagent:sub_1.b", { input: "{}", status: "running" }),
    );
    runs = applySubagentEvent(
      runs,
      "workflow-activity",
      activity("subagent:sub_1.a", {
        input: '{"query":"funding"}',
        status: "done",
        summary: "3 hits",
      }),
    );

    expect(runs["call_1"]?.steps).toEqual([
      {
        id: "subagent:sub_1.a",
        tool: "search",
        input: '{"query":"funding"}',
        status: "done",
        summary: "3 hits",
      },
      {
        id: "subagent:sub_1.b",
        tool: "search",
        input: "{}",
        status: "running",
      },
    ]);
  });

  it("appends text deltas", () => {
    let runs: SubagentRuns = applySubagentEvent(
      {},
      "subagent-started",
      STARTED,
    );
    runs = applySubagentEvent(runs, "subagent-text", {
      callId: "call_1",
      delta: "Fund",
    });
    runs = applySubagentEvent(runs, "subagent-text", {
      callId: "call_1",
      delta: "ing.",
    });

    expect(runs["call_1"]?.text).toBe("Funding.");
  });

  it("records how the run finished", () => {
    let runs: SubagentRuns = applySubagentEvent(
      {},
      "subagent-started",
      STARTED,
    );
    runs = applySubagentEvent(runs, "subagent-finished", {
      ...STARTED,
      status: "needs_input",
    });

    expect(runs["call_1"]?.status).toBe("needs_input");
  });

  it("ignores other activities and runs that never started", () => {
    const runs: SubagentRuns = {};

    expect(
      applySubagentEvent(runs, "workflow-activity", {
        id: "drafting:1",
        type: "drafting",
        data: { callId: "call_1" },
      }),
    ).toBe(runs);
    expect(
      applySubagentEvent(runs, "subagent-text", {
        callId: "call_9",
        delta: "x",
      }),
    ).toBe(runs);
    expect(
      applySubagentEvent(runs, "drafted-fields", { callId: "call_1" }),
    ).toBe(runs);
    expect(applySubagentEvent(runs, "subagent-started", "bad")).toBe(runs);
  });
});

describe("step labels", () => {
  it("humanizes a tool name", () => {
    expect(humanizeToolName("documents_search")).toBe("Documents search");
  });

  it("summarizes the first text input", () => {
    expect(summarizeStepInput('{"query":"EU funding","limit":3}')).toBe(
      "EU funding",
    );
  });

  it("keeps a cut-short input and truncates long ones", () => {
    expect(summarizeStepInput('{"query":"EU fun')).toBe('{"query":"EU fun');
    expect(summarizeStepInput("x".repeat(100), 10)).toBe(`${"x".repeat(9)}…`);
  });
});
