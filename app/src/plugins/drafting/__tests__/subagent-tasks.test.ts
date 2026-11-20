/**
 * Unit tests for the pure subagent task logic.
 */

import { describe, expect, it } from "vitest";
import {
  isActive,
  nextReloadDecision,
  noticeSummary,
  pollDelay,
  type SubagentTask,
  type SubagentTasksResponse,
  shouldDeliver,
} from "../subagent-tasks";

function task(overrides: Partial<SubagentTask> = {}): SubagentTask {
  return {
    taskId: "sub_1",
    subagent: "researcher",
    tool: "research_documents",
    status: "completed",
    mode: "background",
    deliveryState: "none",
    prompt: "Look it up",
    created: 1,
    changed: 2,
    ...overrides,
  };
}

function response(
  overrides: Partial<SubagentTasksResponse> = {},
): SubagentTasksResponse {
  return {
    tasks: [],
    deliverable: false,
    busy: false,
    revision: "1.1",
    ...overrides,
  };
}

describe("isActive", () => {
  it("is false for no tasks", () => {
    expect(isActive(response())).toBe(false);
  });

  it("is true for a pending or running background task", () => {
    expect(isActive(response({ tasks: [task({ status: "pending" })] }))).toBe(
      true,
    );
    expect(isActive(response({ tasks: [task({ status: "running" })] }))).toBe(
      true,
    );
  });

  it("ignores a foreground task that is running", () => {
    expect(
      isActive(
        response({ tasks: [task({ status: "running", mode: "foreground" })] }),
      ),
    ).toBe(false);
  });

  it("is true while a result waits or is claimed", () => {
    expect(
      isActive(response({ tasks: [task({ deliveryState: "waiting" })] })),
    ).toBe(true);
    expect(
      isActive(response({ tasks: [task({ deliveryState: "claimed" })] })),
    ).toBe(true);
  });

  it("is false once everything is delivered", () => {
    expect(
      isActive(response({ tasks: [task({ deliveryState: "delivered" })] })),
    ).toBe(false);
  });
});

describe("pollDelay", () => {
  it("polls fast when a task is active", () => {
    expect(pollDelay(response({ tasks: [task({ status: "running" })] }))).toBe(
      3000,
    );
  });

  it("polls fast when results are deliverable", () => {
    expect(pollDelay(response({ deliverable: true }))).toBe(3000);
  });

  it("polls slowly when idle", () => {
    expect(pollDelay(response())).toBe(20000);
  });
});

describe("shouldDeliver", () => {
  const idle = { threadRunning: false, delivering: false };

  it("delivers when deliverable and nothing runs", () => {
    expect(shouldDeliver(response({ deliverable: true }), idle)).toBe(true);
  });

  it("waits when nothing is deliverable", () => {
    expect(shouldDeliver(response(), idle)).toBe(false);
  });

  it("waits when the session is busy", () => {
    expect(
      shouldDeliver(response({ deliverable: true, busy: true }), idle),
    ).toBe(false);
  });

  it("waits when the thread is running", () => {
    expect(
      shouldDeliver(response({ deliverable: true }), {
        ...idle,
        threadRunning: true,
      }),
    ).toBe(false);
  });

  it("waits when a delivery is already in flight", () => {
    expect(
      shouldDeliver(response({ deliverable: true }), {
        ...idle,
        delivering: true,
      }),
    ).toBe(false);
  });
});

describe("noticeSummary", () => {
  it("names the subagent and the status", () => {
    expect(noticeSummary([task()])).toBe(
      "Background results arrived: researcher completed.",
    );
  });

  it("lists tasks oldest first and names a repeated pair once", () => {
    expect(
      noticeSummary([
        task({ subagent: "researcher", status: "completed" }),
        task({ subagent: "drafter", status: "failed" }),
        task({ subagent: "researcher", status: "completed" }),
      ]),
    ).toBe("Background results arrived: researcher completed, drafter failed.");
  });

  it("writes a status with an underscore as words", () => {
    expect(noticeSummary([task({ status: "needs_input" })])).toBe(
      "Background results arrived: researcher needs input.",
    );
  });

  it("falls back when no task is named", () => {
    expect(noticeSummary([])).toBe("Background results arrived.");
  });
});

describe("nextReloadDecision", () => {
  const quiet = { threadRunning: false, localRunSinceLastPoll: false };

  it("adopts the first revision without a reload", () => {
    expect(
      nextReloadDecision(null, response({ revision: "2.5" }), quiet),
    ).toEqual({ reload: false, revision: "2.5" });
  });

  it("does nothing when the revision is the same", () => {
    expect(
      nextReloadDecision("1.1", response({ revision: "1.1" }), quiet).reload,
    ).toBe(false);
  });

  it("reloads when the revision changed behind the tab's back", () => {
    expect(
      nextReloadDecision("1.1", response({ revision: "3.4" }), quiet),
    ).toEqual({ reload: true, revision: "3.4" });
  });

  it("adopts silently while the thread is running", () => {
    expect(
      nextReloadDecision("1.1", response({ revision: "3.4" }), {
        ...quiet,
        threadRunning: true,
      }),
    ).toEqual({ reload: false, revision: "3.4" });
  });

  it("adopts silently after a run of this tab", () => {
    expect(
      nextReloadDecision("1.1", response({ revision: "3.4" }), {
        ...quiet,
        localRunSinceLastPoll: true,
      }),
    ).toEqual({ reload: false, revision: "3.4" });
  });
});
