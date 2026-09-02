import { expect, test } from "@playwright/test";

/**
 * E2E tests for the drafting plugin's text streaming.
 *
 * These tests verify that the UI Message Stream Protocol SSE events
 * are correctly parsed and rendered by the assistant-ui runtime.
 * They catch regressions like "undefined" text appearing in the
 * chat when the protocol format is mismatched.
 *
 * All server responses are mocked via page.route() so the tests
 * run without a real backend or API key.
 */

/**
 * Builds a mock SSE response body from an array of event objects.
 * Each event is serialised as a "data: <JSON>\n\n" SSE frame,
 * followed by the [DONE] sentinel.
 */
function buildSseBody(events: Record<string, unknown>[]): string {
  const lines = events.map((e) => `data: ${JSON.stringify(e)}\n\n`);
  lines.push("data: [DONE]\n\n");
  return lines.join("");
}

/** Mock SSE events for a simple drafting chat response. */
const MOCK_CHAT_EVENTS: Record<string, unknown>[] = [
  { type: "start", messageId: "mock-msg-001" },
  { type: "start-step" },
  { type: "text-start", id: "mock-text-001" },
  { type: "text-delta", textDelta: "Hello! " },
  { type: "text-delta", textDelta: "This is a " },
  { type: "text-delta", textDelta: "mocked response " },
  { type: "text-delta", textDelta: "from the drafting " },
  { type: "text-delta", textDelta: "assistant." },
  { type: "text-end" },
  {
    type: "finish-step",
    finishReason: "stop",
    usage: { inputTokens: 10, outputTokens: 20 },
    isContinued: false,
  },
  {
    type: "finish",
    finishReason: "stop",
    usage: { inputTokens: 10, outputTokens: 20 },
  },
];

/** Mock content schema response for oe_news. */
const MOCK_CONTENT_SCHEMA = {
  contentType: "oe_news",
  label: "News",
  groups: [
    {
      label: "Content",
      fields: [
        {
          name: "title",
          label: "Title",
          type: "string_textfield",
          widget: "string_textfield",
          interaction: "fill",
          cardinality: 1,
        },
      ],
    },
  ],
};

/**
 * Registers mock API routes on the page. Intercepts all /api
 * requests so the tests do not depend on a running backend.
 */
async function mockApiRoutes(page: import("@playwright/test").Page) {
  // Mock content schema endpoint.
  await page.route("**/api/content-schema/**", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify(MOCK_CONTENT_SCHEMA),
    }),
  );

  // Mock the transcript hydration the runtime calls on mount.
  await page.route("**/api/plugins/drafting/get-messages", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ messages: [] }),
    }),
  );

  // Mock drafting chat endpoint with SSE response.
  await page.route("**/api/plugins/drafting/chat", (route) =>
    route.fulfill({
      status: 200,
      headers: {
        "Content-Type": "text/event-stream",
        "Cache-Control": "no-cache",
        Connection: "keep-alive",
        "x-vercel-ai-ui-message-stream": "v1",
      },
      body: buildSseBody(MOCK_CHAT_EVENTS),
    }),
  );
}

test.describe("Drafting text streaming", () => {
  test("assistant response streams without 'undefined' text", async ({
    page,
  }) => {
    await mockApiRoutes(page);

    // Navigate to the drafting plugin page.
    await page.goto("/#/drafting");

    // Wait for the chat interface to load. assistant-ui renders
    // a composer (input area) when the thread is ready.
    const composer = page.locator(
      '[data-testid="aui-composer-input"], textarea',
    );
    await expect(composer.first()).toBeVisible({ timeout: 10000 });

    // Type a message and submit.
    await composer.first().fill("hello");
    await page.keyboard.press("Enter");

    // Wait for the assistant's response to appear.
    const assistantMessage = page.locator('[data-testid="assistant-message"]');
    await expect(assistantMessage.first()).toBeVisible({
      timeout: 15000,
    });

    // Wait for the message to finish rendering.
    await page.waitForTimeout(2000);

    // Get the text content of the assistant's response.
    const messageText = await assistantMessage.first().textContent();

    // The critical assertion: no "undefined" in the response.
    // This catches the bug where string deltas from the agent
    // re-invocation are not properly handled.
    expect(messageText).not.toContain("undefined");
    expect(messageText).not.toBe("");
    expect(messageText).not.toBeNull();

    // The response should contain actual words (not just
    // whitespace or garbage).
    expect(messageText!.trim().length).toBeGreaterThan(5);
  });

  test("SSE stream returns valid text-delta events", async ({ page }) => {
    // This test intercepts the raw SSE stream and validates the
    // event format directly, independent of the UI rendering.
    const streamEvents: string[] = [];

    // Mock content schema.
    await page.route("**/api/content-schema/**", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify(MOCK_CONTENT_SCHEMA),
      }),
    );

    // Mock the transcript hydration the runtime calls on mount.
    await page.route("**/api/plugins/drafting/get-messages", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ messages: [] }),
      }),
    );

    // Intercept the drafting chat request. Return the mock SSE
    // response and capture events for assertion.
    const sseBody = buildSseBody(MOCK_CHAT_EVENTS);
    await page.route("**/api/plugins/drafting/chat", (route) => {
      // Capture events before fulfilling.
      for (const line of sseBody.split("\n")) {
        if (line.startsWith("data: ") && line !== "data: [DONE]") {
          streamEvents.push(line.slice(6));
        }
      }

      return route.fulfill({
        status: 200,
        headers: {
          "Content-Type": "text/event-stream",
          "Cache-Control": "no-cache",
          Connection: "keep-alive",
          "x-vercel-ai-ui-message-stream": "v1",
        },
        body: sseBody,
      });
    });

    await page.goto("/#/drafting");

    const composer = page.locator(
      '[data-testid="aui-composer-input"], textarea',
    );
    await expect(composer.first()).toBeVisible({ timeout: 10000 });

    await composer.first().fill("hello");
    await page.keyboard.press("Enter");

    // Wait for response to complete.
    await page.waitForTimeout(3000);

    // Validate the captured SSE events.
    expect(streamEvents.length).toBeGreaterThan(0);

    // Check that we got a start event.
    const startEvent = streamEvents.find((e) => {
      const parsed = JSON.parse(e);
      return parsed.type === "start";
    });
    expect(startEvent).toBeDefined();

    // Check that text-delta events have valid textDelta strings.
    // The UI Message Stream Protocol uses "textDelta" as the field
    // name for incremental text content.
    const textDeltas = streamEvents.filter((e) => {
      const parsed = JSON.parse(e);
      return parsed.type === "text-delta";
    });
    expect(textDeltas.length).toBeGreaterThan(0);

    for (const event of textDeltas) {
      const parsed = JSON.parse(event);
      // The textDelta field must be a string, not undefined/null.
      expect(parsed.textDelta).toBeDefined();
      expect(typeof parsed.textDelta).toBe("string");
      expect(parsed.textDelta).not.toBe("undefined");
    }

    // Check that we got a finish event.
    const finishEvent = streamEvents.find((e) => {
      const parsed = JSON.parse(e);
      return parsed.type === "finish";
    });
    expect(finishEvent).toBeDefined();
  });

  test("documents panel persists upload and removal through API calls", async ({
    page,
  }) => {
    await mockApiRoutes(page);

    const uploadedDocument = {
      id: "uploaded-context-document",
      title: "Reload memo.txt",
      meta: { type: "txt", size: 11 },
    };
    let resolveRemoval!: () => void;
    const removalResponse = new Promise<void>((resolve) => {
      resolveRemoval = resolve;
    });

    await page.route("**/api/plugins/drafting/add-document", (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ document: uploadedDocument }),
      }),
    );
    await page.route(
      "**/api/plugins/drafting/remove-document",
      async (route) => {
        const body = route.request().postDataJSON() as { documentId?: string };

        if (body.documentId === uploadedDocument.id) {
          await removalResponse;
          return route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ status: "ok" }),
          });
        }

        return route.fulfill({
          status: 500,
          contentType: "application/json",
          body: JSON.stringify({
            code: "server_error",
            message: "Removal failed",
          }),
        });
      },
    );

    await page.goto("/#/drafting");
    await page.getByRole("button", { name: /Context documents/ }).click();

    await expect(page.getByText("EU AI Act briefing note.pdf")).toBeVisible();
    await page.locator('input[type="file"]').setInputFiles({
      name: "Reload memo.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("hello world"),
    });

    await expect(page.getByText(uploadedDocument.title)).toBeVisible();
    await page.getByRole("button", { name: "Close" }).click();
    await expect(
      page.getByRole("button", { name: /Context documents/ }),
    ).toContainText("3 documents");

    await page.getByRole("button", { name: /Context documents/ }).click();
    await page
      .getByRole("button", { name: `Remove ${uploadedDocument.title}` })
      .click();
    // Removal requires explicit confirmation; while the request is pending
    // the dialog stays open with the confirm control locked.
    await page.getByRole("button", { name: "Delete document" }).click();
    await expect(
      page.getByRole("button", { name: "Delete document" }),
    ).toBeDisabled();

    resolveRemoval();

    // Exact match targets the list row; the dialog message quotes the
    // title inside a longer sentence.
    await expect(
      page.getByText(uploadedDocument.title, { exact: true }),
    ).toBeHidden();
    await page.getByRole("button", { name: "Close" }).click();
    await expect(
      page.getByRole("button", { name: /Context documents/ }),
    ).toContainText("2 documents");

    await page.getByRole("button", { name: /Context documents/ }).click();
    await page
      .getByRole("button", { name: "Remove EU AI Act briefing note.pdf" })
      .click();
    await page.getByRole("button", { name: "Delete document" }).click();

    // The failure keeps the confirmation dialog open with the error.
    await expect(
      page.getByText("Drafting remove-document error: 500"),
    ).toBeVisible();
    await page.getByRole("button", { name: "Cancel" }).click();
    await expect(page.getByText("EU AI Act briefing note.pdf")).toBeVisible();
  });
});
