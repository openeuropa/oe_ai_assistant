import { expect, type Page, test } from "@playwright/test";

/**
 * E2E tests for the subagent card of the research_documents tool call.
 *
 * The chat stream is mocked with the frames the editorial turn sends around a
 * foreground subagent run: its start, one activity per tool call, the text it
 * streams, the tool result, and its end. All server responses are mocked via
 * page.route(), so the tests run without a real backend.
 */

const CALL_ID = "call_research_1";
const TASK_ID = "sub_1";
const EDITORIAL_TEXT = "Here is what the research found.";

const COMPLETED_RESULT = {
  task_id: TASK_ID,
  subagent: "researcher",
  status: "completed",
  report:
    "Funding for the programme is 5M euro [doc:1 p.2]. The budget was cut later [doc:9 p.4].",
  citations: [
    { document_id: 1, page: 2, verified: true },
    { document_id: 9, page: 4, verified: false },
  ],
};

const RANGE_RESULT = {
  task_id: TASK_ID,
  subagent: "researcher",
  status: "completed",
  report:
    "The budget grew over two years [doc:1 p.4–5], then fell [doc:9 p.2, 7].",
  citations: [
    { document_id: 1, page: 4, verified: true },
    { document_id: 1, page: 5, verified: true },
    { document_id: 9, page: 2, verified: true },
    { document_id: 9, page: 7, verified: false },
  ],
};

const NEEDS_INPUT_RESULT = {
  task_id: TASK_ID,
  subagent: "researcher",
  status: "needs_input",
  question: "Which year should the funding figures cover?",
};

/** Builds a mock SSE body from event objects, ending with [DONE]. */
function buildSseBody(events: Record<string, unknown>[]): string {
  const lines = events.map((e) => `data: ${JSON.stringify(e)}\n\n`);
  lines.push("data: [DONE]\n\n");
  return lines.join("");
}

/** A data-workflow-activity frame, as Neuron's Vercel adapter writes it. */
function activityFrame(childCallId: string, data: Record<string, unknown>) {
  return {
    type: "data-workflow-activity",
    data: {
      id: `subagent:${TASK_ID}.${childCallId}`,
      type: "subagent",
      data: {
        callId: CALL_ID,
        taskId: TASK_ID,
        tool: "documents_search",
        ...data,
      },
    },
    transient: true,
  };
}

/** The frames of a turn whose research call settles with the given result. */
function researchStream(result: Record<string, unknown>) {
  const label = { callId: CALL_ID, taskId: TASK_ID, subagent: "researcher" };
  return [
    { type: "start", messageId: "mock-msg-001" },
    { type: "start-step" },
    {
      type: "tool-input-start",
      toolCallId: CALL_ID,
      toolName: "research_documents",
    },
    {
      type: "tool-input-available",
      toolCallId: CALL_ID,
      toolName: "research_documents",
      input: { prompt: "What is the funding?" },
    },
    { type: "data-subagent-started", data: label, transient: true },
    activityFrame("c1", {
      input: '{"query":"programme funding"}',
      status: "running",
    }),
    activityFrame("c1", {
      input: '{"query":"programme funding"}',
      status: "done",
      summary: "2 passages",
    }),
    ...["Reading ", "the budget ", "table."].map((delta) => ({
      type: "data-subagent-text",
      data: { callId: CALL_ID, taskId: TASK_ID, delta },
      transient: true,
    })),
    {
      type: "tool-output-available",
      toolCallId: CALL_ID,
      // The tool answers with JSON text, as the adapter writes it.
      output: JSON.stringify(result),
    },
    {
      type: "data-subagent-finished",
      data: { ...label, status: result["status"] },
      transient: true,
    },
    { type: "finish-step" },
    { type: "start-step" },
    { type: "text-start", id: "mock-text-001" },
    { type: "text-delta", textDelta: EDITORIAL_TEXT },
    { type: "text-end" },
    { type: "finish-step", finishReason: "stop", isContinued: false },
    { type: "finish", finishReason: "stop" },
  ];
}

/** Registers the mocks the tests share and serves the given chat frames. */
async function mockApiRoutes(
  page: Page,
  frames: Record<string, unknown>[],
  tasks: Record<string, unknown>[] = [],
) {
  await page.route("**/session/token", (route) =>
    route.fulfill({
      status: 200,
      contentType: "text/plain",
      body: "e2e-csrf-token",
    }),
  );
  await page.route("**/api/plugins/drafting/get-messages", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ messages: [] }),
    }),
  );
  await page.route("**/api/plugins/drafting/tasks", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        tasks,
        deliverable: false,
        busy: false,
        revision: "0.0",
      }),
    }),
  );
  await page.route("**/api/plugins/drafting/list-documents", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        documents: [
          {
            id: "1",
            title: "Briefing note.pdf",
            status: "done",
            meta: { type: "pdf", size: 100 },
          },
        ],
      }),
    }),
  );
  await page.route("**/api/plugins/drafting/chat", (route) =>
    route.fulfill({
      status: 200,
      headers: {
        "Content-Type": "text/event-stream",
        "Cache-Control": "no-cache",
        "x-vercel-ai-ui-message-stream": "v1",
      },
      body: buildSseBody(frames),
    }),
  );
}

/** Sends a message and waits for the reply of the mocked turn. */
async function sendMessage(page: Page) {
  await page.goto("/#/drafting");
  const composer = page.locator('[data-testid="aui-composer-input"], textarea');
  await expect(composer.first()).toBeVisible({ timeout: 10000 });
  await composer.first().fill("Research the funding");
  await page.keyboard.press("Enter");
  await expect(page.getByText(EDITORIAL_TEXT)).toBeVisible({ timeout: 15000 });
}

test.describe("Subagent card", () => {
  test("a completed run shows its steps, report, and citation chips", async ({
    page,
  }) => {
    await mockApiRoutes(page, researchStream(COMPLETED_RESULT));
    await sendMessage(page);

    const card = page.getByTestId("subagent-card");
    await expect(card).toBeVisible();
    await expect(card).toContainText("Researcher");
    await expect(page.getByTestId("subagent-badge")).toHaveText("Completed");

    // The step the activity frames reported is kept, updated to done.
    const step = card.getByTestId("subagent-step");
    await expect(step).toHaveCount(1);
    await expect(step).toContainText("Documents search");
    await expect(step).toContainText("programme funding");
    await expect(step).toHaveAttribute("data-status", "done");

    // The report is rendered, with each marker as a chip.
    await expect(page.getByTestId("subagent-report")).toContainText(
      "Funding for the programme is 5M euro",
    );
    const chips = card.getByTestId("citation-chip");
    await expect(chips).toHaveCount(2);
    await expect(chips.first()).toContainText("Briefing note.pdf · p.2");
    await expect(chips.first()).toHaveAttribute("data-verified", "true");
    await expect(chips.nth(1)).toContainText("Document 9 · p.4");
    await expect(chips.nth(1)).toHaveAttribute("data-verified", "false");

    // The sources list repeats both citations.
    await expect(card.getByTestId("citation-list").locator("li")).toHaveCount(
      2,
    );
  });

  test("a run that needs input shows the question", async ({ page }) => {
    await mockApiRoutes(page, researchStream(NEEDS_INPUT_RESULT));
    await sendMessage(page);

    const card = page.getByTestId("subagent-card");
    await expect(page.getByTestId("subagent-badge")).toHaveText("Needs input");
    await expect(card).toContainText("The researcher asks:");
    await expect(card).toContainText(
      "Which year should the funding figures cover?",
    );
    await expect(card.getByTestId("citation-chip")).toHaveCount(0);
  });

  test("a reloaded pending call reports how its task ended", async ({
    page,
  }) => {
    await mockApiRoutes(page, researchStream(COMPLETED_RESULT));
    // Routes registered later win, so this transcript replaces the empty one.
    await page.route("**/api/plugins/drafting/get-messages", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          messages: [
            { role: "user", content: "Research it in the background." },
            {
              role: "assistant",
              at: "2026-01-01T10:00:00+00:00",
              toolCalls: [
                {
                  function: { name: "research_documents" },
                  result: {
                    task_id: TASK_ID,
                    subagent: "researcher",
                    status: "pending",
                  },
                  live: { status: "completed", deliveryState: "delivered" },
                },
              ],
            },
          ],
        }),
      }),
    );
    await page.goto("/#/drafting");

    await expect(page.getByTestId("subagent-pending")).toHaveText(
      "Done, results delivered",
    );
  });

  test("a range or a list of pages is one chip", async ({ page }) => {
    await mockApiRoutes(page, researchStream(RANGE_RESULT));
    await sendMessage(page);

    const chips = page
      .getByTestId("subagent-card")
      .getByTestId("citation-chip");
    await expect(chips).toHaveCount(2);
    await expect(chips.first()).toContainText("Briefing note.pdf · p.4–5");
    await expect(chips.first()).toHaveAttribute("data-verified", "true");
    await expect(chips.nth(1)).toContainText("Document 9 · p.2, 7");
    await expect(chips.nth(1)).toHaveAttribute("data-verified", "partial");
  });

  test("a question the task moved on from reads as answered", async ({
    page,
  }) => {
    await mockApiRoutes(page, researchStream(NEEDS_INPUT_RESULT), [
      {
        taskId: TASK_ID,
        subagent: "researcher",
        tool: "research_documents",
        status: "completed",
        mode: "foreground",
        deliveryState: "none",
        continuations: 1,
        prompt: "Research",
        created: 1,
        changed: 2,
      },
    ]);
    await sendMessage(page);

    await expect(page.getByTestId("subagent-answered")).toHaveText(
      "Answered — continued below",
    );
    await expect(page.getByTestId("subagent-badge")).toHaveText("Answered");
  });

  test("a pending call follows the ledger of the poll", async ({ page }) => {
    await mockApiRoutes(page, researchStream(COMPLETED_RESULT), [
      {
        taskId: TASK_ID,
        subagent: "researcher",
        tool: "research_documents",
        status: "completed",
        mode: "background",
        deliveryState: "delivered",
        continuations: 0,
        prompt: "Research",
        created: 1,
        changed: 2,
      },
    ]);
    await page.route("**/api/plugins/drafting/get-messages", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          messages: [
            { role: "user", content: "Research it in the background." },
            {
              role: "assistant",
              at: "2026-01-01T10:00:00+00:00",
              toolCalls: [
                {
                  function: { name: "research_documents" },
                  result: {
                    task_id: TASK_ID,
                    subagent: "researcher",
                    status: "pending",
                  },
                  live: { status: "running", deliveryState: "none" },
                },
              ],
            },
          ],
        }),
      }),
    );
    await page.goto("/#/drafting");

    // The transcript said running; the poll says the result was delivered.
    await expect(page.getByTestId("subagent-badge")).toHaveText("Delivered");
    await expect(page.getByTestId("subagent-pending")).toHaveText(
      "Done, results delivered",
    );
  });

  test("a call that ended in a tool error shows the reason", async ({
    page,
  }) => {
    const frames = researchStream(COMPLETED_RESULT).map((frame) =>
      frame["type"] === "tool-output-available"
        ? {
            type: "tool-output-error",
            toolCallId: CALL_ID,
            errorText: "That task is not waiting for an answer.",
          }
        : frame,
    );
    await mockApiRoutes(page, frames);
    await sendMessage(page);

    await expect(page.getByTestId("subagent-error")).toHaveText(
      "Couldn't continue the task: That task is not waiting for an answer.",
    );
    await expect(page.getByTestId("subagent-badge")).toHaveText("Failed");
  });
});
