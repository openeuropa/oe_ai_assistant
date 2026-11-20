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
    continuations: 0,
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
  const quiet = { threadRunning: false, ownDeliveries: new Set<string>() };
  const delivered = (...ids: string[]) =>
    response({
      tasks: ids.map((id) => task({ taskId: id, deliveryState: "delivered" })),
    });

  it("only sets the baseline on the first poll", () => {
    const decision = nextReloadDecision(null, delivered("sub_1"), quiet);

    expect(decision.reload).toBe(false);
    expect([...decision.delivered]).toEqual(["sub_1"]);
  });

  it("does nothing while no task is newly delivered", () => {
    expect(
      nextReloadDecision(new Set(["sub_1"]), delivered("sub_1"), quiet).reload,
    ).toBe(false);
    expect(nextReloadDecision(new Set(), response(), quiet).reload).toBe(false);
  });

  it("reloads when someone else delivered a task", () => {
    const decision = nextReloadDecision(new Set(), delivered("sub_1"), quiet);

    expect(decision.reload).toBe(true);
    expect([...decision.delivered]).toEqual(["sub_1"]);
  });

  it("does not reload for a task this tab delivered itself", () => {
    const decision = nextReloadDecision(new Set(), delivered("sub_1"), {
      ...quiet,
      ownDeliveries: new Set(["sub_1"]),
    });

    expect(decision.reload).toBe(false);
    expect([...decision.delivered]).toEqual(["sub_1"]);
  });

  it("reloads for a foreign task delivered beside an own one", () => {
    expect(
      nextReloadDecision(new Set(), delivered("sub_1", "sub_2"), {
        ...quiet,
        ownDeliveries: new Set(["sub_1"]),
      }).reload,
    ).toBe(true);
  });

  it("defers the reload while the thread is running", () => {
    const running = { ...quiet, threadRunning: true };
    const deferred = nextReloadDecision(new Set(), delivered("sub_1"), running);

    expect(deferred.reload).toBe(false);
    expect([...deferred.delivered]).toEqual([]);

    // The next poll, once the run ended, still sees the task as new.
    expect(
      nextReloadDecision(deferred.delivered, delivered("sub_1"), quiet),
    ).toMatchObject({ reload: true });
  });
});
