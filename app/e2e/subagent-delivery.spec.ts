import { expect, test } from "@playwright/test";

/**
 * E2E tests for the live delivery of background subagent results.
 *
 * When the tasks endpoint reports results waiting and the tab is idle, the
 * browser starts the follow-up turn itself: a chat request flagged
 * runConfig.custom.deliver, with no new user message. All server responses
 * are mocked via page.route(), so the tests run without a real backend.
 */

const NOTICE = "Background results arrived: researcher completed.";
const REPLY = "The research found two relevant sources.";

/** Builds a mock SSE body from event objects, ending with [DONE]. */
function buildSseBody(events: Record<string, unknown>[]): string {
  const lines = events.map((e) => `data: ${JSON.stringify(e)}\n\n`);
  lines.push("data: [DONE]\n\n");
  return lines.join("");
}

/** The stream of the delivery turn: one text reply. */
const DELIVERY_EVENTS: Record<string, unknown>[] = [
  { type: "start", messageId: "mock-delivery-001" },
  { type: "start-step" },
  { type: "text-start", id: "mock-text-001" },
  { type: "text-delta", textDelta: REPLY },
  { type: "text-end" },
  { type: "finish-step", finishReason: "stop", isContinued: false },
  { type: "finish", finishReason: "stop" },
];

const TASK = {
  taskId: "sub_1",
  subagent: "researcher",
  tool: "research_documents",
  status: "completed",
  mode: "background",
  deliveryState: "waiting",
  prompt: "Find sources",
  created: 1,
  changed: 2,
};

const HISTORY = [
  { role: "user", content: "Research the briefing note." },
  { role: "assistant", content: "I started the research." },
];

/**
 * Registers the mocks the delivery tests share and returns what they saw.
 *
 * The tasks endpoint answers deliverable until a chat request claims the
 * results, then nothing waits.
 */
async function mockApiRoutes(
  page: import("@playwright/test").Page,
  chat: (route: import("@playwright/test").Route) => Promise<void>,
) {
  const seen = {
    chatBodies: [] as Record<string, unknown>[],
    taskCalls: 0,
    messageCalls: 0,
  };

  await page.route("**/session/token", (route) =>
    route.fulfill({
      status: 200,
      contentType: "text/plain",
      body: "e2e-csrf-token",
    }),
  );

  await page.route("**/api/plugins/drafting/get-messages", (route) => {
    seen.messageCalls += 1;
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ messages: HISTORY }),
    });
  });

  await page.route("**/api/plugins/drafting/tasks", (route) => {
    seen.taskCalls += 1;
    const first = seen.chatBodies.length === 0;
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        tasks: [first ? TASK : { ...TASK, deliveryState: "delivered" }],
        deliverable: first,
        busy: false,
        revision: "2.2",
      }),
    });
  });

  await page.route("**/api/plugins/drafting/chat", async (route) => {
    seen.chatBodies.push(route.request().postDataJSON());
    await chat(route);
  });

  return seen;
}

test.describe("Background result delivery", () => {
  test("an idle tab streams the delivery turn without a user message", async ({
    page,
  }) => {
    const seen = await mockApiRoutes(page, (route) =>
      route.fulfill({
        status: 200,
        headers: {
          "Content-Type": "text/event-stream",
          "Cache-Control": "no-cache",
          "x-vercel-ai-ui-message-stream": "v1",
        },
        body: buildSseBody(DELIVERY_EVENTS),
      }),
    );

    await page.goto("/#/drafting");

    await expect(page.getByText(REPLY)).toBeVisible({ timeout: 15000 });
    await expect(page.getByText(NOTICE)).toBeVisible();

    expect(seen.chatBodies).toHaveLength(1);
    const body = seen.chatBodies[0] as {
      runConfig?: { custom?: { deliver?: boolean } };
      messages: Array<{ role: string }>;
    };
    expect(body.runConfig?.custom?.deliver).toBe(true);
    // No message was typed: the last message the tab sent is the notice
    // chip's assistant message, not a user turn.
    expect(body.messages.at(-1)?.role).not.toBe("user");

    // Nothing waits any more, so the following polls start no second turn.
    await page.waitForTimeout(4500);
    expect(seen.taskCalls).toBeGreaterThan(1);
    expect(seen.chatBodies).toHaveLength(1);
  });

  test("a refused delivery reloads the thread quietly", async ({ page }) => {
    const seen = await mockApiRoutes(page, (route) =>
      route.fulfill({
        status: 409,
        contentType: "application/json",
        body: JSON.stringify({
          code: "nothing_to_deliver",
          message: "Nothing waits.",
        }),
      }),
    );

    await page.goto("/#/drafting");
    await expect(page.getByText("I started the research.")).toBeVisible({
      timeout: 15000,
    });

    // The refused turn is followed by a second load of the transcript.
    await expect.poll(() => seen.messageCalls, { timeout: 15000 }).toBe(2);

    await expect(page.getByText(NOTICE)).toBeHidden();
    await expect(page.getByText(/409|nothing_to_deliver/)).toBeHidden();
    await expect(page.getByText("I started the research.")).toBeVisible();
    expect(seen.chatBodies).toHaveLength(1);
  });

  test("a delivery the worker made shows in the open tab", async ({ page }) => {
    let delivered = false;
    let messageCalls = 0;
    let chatCalls = 0;

    await page.route("**/session/token", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/plain",
        body: "e2e-csrf-token",
      }),
    );
    // The first load has no reply. The reload after the worker's delivery
    // has the notice and the reply the worker wrote.
    await page.route("**/api/plugins/drafting/get-messages", (route) => {
      messageCalls += 1;
      const messages =
        messageCalls === 1
          ? HISTORY
          : [
              ...HISTORY,
              {
                role: "event",
                type: "background_results",
                summary: NOTICE,
                at: "2026-01-01T10:00:00+00:00",
                tasks: [],
              },
              { role: "assistant", content: REPLY },
            ];
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ messages }),
      });
    });
    // The task runs until the test flips it to delivered, with no deliver
    // request from this tab in between.
    await page.route("**/api/plugins/drafting/tasks", (route) => {
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          tasks: [
            !delivered
              ? { ...TASK, status: "running", deliveryState: "none" }
              : { ...TASK, deliveryState: "delivered" },
          ],
          deliverable: false,
          busy: false,
          revision: delivered ? "4.4" : "2.2",
        }),
      });
    });
    await page.route("**/api/plugins/drafting/chat", (route) => {
      chatCalls += 1;
      return route.abort();
    });

    await page.goto("/#/drafting");
    await expect(page.getByText("I started the research.")).toBeVisible({
      timeout: 15000,
    });
    await expect(page.getByText(REPLY)).toBeHidden();

    // The worker delivers while the tab sits idle; the next poll shows it.
    delivered = true;
    await expect(page.getByText(REPLY)).toBeVisible({ timeout: 15000 });
    expect(messageCalls).toBe(2);
    expect(chatCalls).toBe(0);
  });

  test("a failed delivery backs off and does not block the later reload", async ({
    page,
  }) => {
    const chatTimes: number[] = [];
    let messageCalls = 0;
    let workerDelivered = false;

    await page.route("**/session/token", (route) =>
      route.fulfill({
        status: 200,
        contentType: "text/plain",
        body: "e2e-csrf-token",
      }),
    );
    await page.route("**/api/plugins/drafting/get-messages", (route) => {
      messageCalls += 1;
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ messages: HISTORY }),
      });
    });
    // The results wait until the test lets the worker deliver them.
    await page.route("**/api/plugins/drafting/tasks", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          tasks: [
            workerDelivered ? { ...TASK, deliveryState: "delivered" } : TASK,
          ],
          deliverable: !workerDelivered,
          busy: false,
          revision: workerDelivered ? "4.4" : "2.2",
        }),
      }),
    );
    await page.route("**/api/plugins/drafting/chat", (route) => {
      chatTimes.push(Date.now());
      return route.abort();
    });

    await page.goto("/#/drafting");
    await expect.poll(() => chatTimes.length, { timeout: 30000 }).toBe(2);
    expect(chatTimes[1] - chatTimes[0]).toBeGreaterThanOrEqual(4000);

    // The failed deliveries restore the thread locally; only the initial
    // load reached the server.
    expect(messageCalls).toBe(1);
    workerDelivered = true;
    await expect.poll(() => messageCalls, { timeout: 30000 }).toBe(2);
  });

  test("an own delivery of a draft task reloads the thread", async ({
    page,
  }) => {
    const seen = await mockApiRoutes(page, (route) =>
      route.fulfill({
        status: 200,
        headers: {
          "Content-Type": "text/event-stream",
          "Cache-Control": "no-cache",
          "x-vercel-ai-ui-message-stream": "v1",
        },
        body: buildSseBody(DELIVERY_EVENTS),
      }),
    );
    // The default tasks mock reports TASK, which is not a draft task.
    await page.route("**/api/plugins/drafting/tasks", (route) => {
      const first = seen.chatBodies.length === 0;
      const draft = { ...TASK, tool: "drafting_draft_content" };
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          tasks: [first ? draft : { ...draft, deliveryState: "delivered" }],
          deliverable: first,
          busy: false,
          revision: "2.2",
        }),
      });
    });

    await page.goto("/#/drafting");
    // The own delivery is followed by a second load of the transcript.
    await expect.poll(() => seen.messageCalls, { timeout: 15000 }).toBe(2);
    expect(seen.chatBodies).toHaveLength(1);
  });
});
