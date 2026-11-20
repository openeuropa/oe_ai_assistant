import { describe, expect, it } from "vitest";
import type { SubagentCallResult } from "../subagent-result";
import {
  badgeKind,
  effectiveLive,
  isAnswered,
  pendingMessage,
} from "../subagent-status";
import type { SubagentTask } from "../subagent-tasks";

function result(
  overrides: Partial<SubagentCallResult> = {},
): SubagentCallResult {
  return {
    taskId: "sub_1",
    subagent: "researcher",
    status: "pending",
    report: "",
    question: "",
    error: "",
    note: "",
    citations: [],
    live: null,
    ...overrides,
  };
}

function task(overrides: Partial<SubagentTask> = {}): SubagentTask {
  return {
    taskId: "sub_1",
    subagent: "researcher",
    tool: "research_documents",
    status: "completed",
    mode: "background",
    deliveryState: "none",
    continuations: 0,
    prompt: "",
    created: 1,
    changed: 2,
    ...overrides,
  };
}

const base = { live: null, answered: false, abandoned: false };

describe("effectiveLive", () => {
  it("prefers the ledger of the latest poll", () => {
    expect(
      effectiveLive(
        { status: "running", deliveryState: "none" },
        task({ deliveryState: "delivered" }),
      ),
    ).toEqual({ status: "completed", deliveryState: "delivered" });
  });

  it("falls back on the state the transcript joined", () => {
    const live = { status: "running", deliveryState: "none" };
    expect(effectiveLive(live, undefined)).toBe(live);
    expect(effectiveLive(null, undefined)).toBeNull();
  });
});

describe("badgeKind", () => {
  it("says Background only while the task is pending or running", () => {
    const pending = result();
    expect(badgeKind({ ...base, result: pending })).toBe("background");
    expect(
      badgeKind({
        ...base,
        result: pending,
        live: { status: "running", deliveryState: "none" },
      }),
    ).toBe("background");
  });

  it("says how a background task ended", () => {
    const pending = result();
    const live = (status: string, deliveryState: string) =>
      badgeKind({ ...base, result: pending, live: { status, deliveryState } });

    expect(live("completed", "waiting")).toBe("completed");
    expect(live("completed", "delivered")).toBe("delivered");
    expect(live("failed", "waiting")).toBe("failed");
  });

  it("reads a foreground result by its own status", () => {
    expect(
      badgeKind({ ...base, result: result({ status: "completed" }) }),
    ).toBe("completed");
    expect(
      badgeKind({ ...base, result: result({ status: "needs_input" }) }),
    ).toBe("needs_input");
    expect(badgeKind({ ...base, result: result({ status: "failed" }) })).toBe(
      "failed",
    );
  });

  it("reads the run while no result arrived", () => {
    expect(badgeKind({ ...base, result: null, runStatus: "running" })).toBe(
      "running",
    );
    expect(badgeKind({ ...base, result: null, runStatus: "failed" })).toBe(
      "failed",
    );
    expect(badgeKind({ ...base, result: null, abandoned: true })).toBe(
      "failed",
    );
  });

  it("says Answered for a question the task moved on from", () => {
    expect(
      badgeKind({
        ...base,
        result: result({ status: "needs_input" }),
        answered: true,
      }),
    ).toBe("answered");
  });
});

describe("isAnswered", () => {
  const asked = result({ status: "needs_input" });

  it("is true once the task is no longer waiting for the editor", () => {
    expect(isAnswered(asked, task({ status: "completed" }))).toBe(true);
    expect(
      isAnswered(asked, task({ status: "needs_input", continuations: 1 })),
    ).toBe(true);
  });

  it("is false while the question is open or the ledger is unknown", () => {
    expect(isAnswered(asked, task({ status: "needs_input" }))).toBe(false);
    expect(isAnswered(asked, undefined)).toBe(false);
    expect(
      isAnswered(result({ status: "completed" }), task({ continuations: 2 })),
    ).toBe(false);
  });
});

describe("pendingMessage", () => {
  it("follows the ledger state", () => {
    expect(pendingMessage(null, "Drafting")).toBe(
      "Drafting in the background…",
    );
    expect(
      pendingMessage({ status: "failed", deliveryState: "none" }, "Drafting"),
    ).toBe("Failed");
    expect(
      pendingMessage(
        { status: "completed", deliveryState: "delivered" },
        "Drafting",
      ),
    ).toBe("Done, results delivered");
    expect(
      pendingMessage(
        { status: "completed", deliveryState: "waiting" },
        "Drafting",
      ),
    ).toBe("Done, waiting to be delivered");
  });
});
