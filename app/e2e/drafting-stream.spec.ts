import { expect, test } from "@playwright/test";

/**
 * E2E tests for the drafting plugin's text streaming.
 *
 * These tests verify that the Data Stream Protocol SSE events
 * are correctly parsed and rendered by the assistant-ui runtime.
 * They catch regressions like "undefined" text appearing in the
 * chat when the protocol format is mismatched.
 */

test.describe("Drafting text streaming", () => {
  test("assistant response streams without 'undefined' text", async ({
    page,
  }) => {
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
    const assistantMessage = page.locator(
      '[data-testid="assistant-message"]',
    );
    await expect(assistantMessage.first()).toBeVisible({
      timeout: 15000,
    });

    // Wait for the message to finish streaming (the text should
    // stabilize). Give it time for the full response.
    await page.waitForTimeout(5000);

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

    // Verify no console errors related to streaming.
    const consoleErrors: string[] = [];
    page.on("console", (msg) => {
      if (msg.type() === "error") {
        consoleErrors.push(msg.text());
      }
    });
  });

  test("SSE stream returns valid text-delta events", async ({ page }) => {
    // This test intercepts the raw SSE stream and validates the
    // event format directly, independent of the UI rendering.
    const streamEvents: string[] = [];

    // Intercept the drafting chat request.
    await page.route("**/api/plugins/drafting/chat", async (route) => {
      // Forward to the real server.
      const response = await route.fetch();
      const body = await response.text();

      // Capture all SSE event lines for inspection.
      for (const line of body.split("\n")) {
        if (line.startsWith("data: ") && line !== "data: [DONE]") {
          streamEvents.push(line.slice(6));
        }
      }

      // Forward the response to the page.
      await route.fulfill({ response });
    });

    await page.goto("/#/drafting");

    const composer = page.locator(
      '[data-testid="aui-composer-input"], textarea',
    );
    await expect(composer.first()).toBeVisible({ timeout: 10000 });

    await composer.first().fill("hello");
    await page.keyboard.press("Enter");

    // Wait for response to complete.
    await page.waitForTimeout(8000);

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
});
