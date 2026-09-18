import { expect, test } from "@playwright/test";

/**
 * E2E tests for the context documents status badges.
 *
 * All server responses are mocked via page.route(): list-documents answers
 * a scripted sequence of statuses so the badge transitions and the retry
 * control can be asserted without a real backend.
 */

/** A document as the list-documents action returns it. */
function document(status: string) {
  return {
    id: "doc-1",
    title: "Briefing.pdf",
    status,
    meta: { type: "pdf", size: 1024 },
  };
}

/** Mutable server state the test drives between assertions. */
interface MockDocumentState {
  /** Status list-documents currently answers. */
  status: string;
  /** Bodies of the extract-document calls received so far. */
  extractCalls: string[];
}

/**
 * Registers the routes every test needs.
 *
 * list-documents answers the current status of the state object, whatever
 * the number of calls, so React's development double mount and the polling
 * cadence do not affect the script. extract-document moves the state to
 * extracting, as the real action does for a resting document.
 */
async function mockDocumentRoutes(
  page: import("@playwright/test").Page,
  status: string,
): Promise<MockDocumentState> {
  const state: MockDocumentState = { status, extractCalls: [] };

  await page.route("**/session/token", (route) =>
    route.fulfill({ status: 200, contentType: "text/plain", body: "token" }),
  );
  await page.route("**/api/content-schema/**", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        contentType: "oe_news",
        label: "News",
        groups: [],
      }),
    }),
  );
  await page.route("**/api/plugins/drafting/get-messages", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ messages: [] }),
    }),
  );
  await page.route("**/api/plugins/drafting/list-documents", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ documents: [document(state.status)] }),
    }),
  );
  await page.route("**/api/plugins/drafting/extract-document", (route) => {
    state.extractCalls.push(route.request().postData() ?? "");
    state.status = "extracting";
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ status: "extracting" }),
    });
  });

  return state;
}

/** Opens the context documents pane from the composer tab. */
async function openDocumentsPane(page: import("@playwright/test").Page) {
  await page.goto("/#/drafting");
  await page.getByRole("button", { name: /Context documents/ }).click();
}

test.describe("Context document status", () => {
  test("badge follows the polled status until the document is ready", async ({
    page,
  }) => {
    const state = await mockDocumentRoutes(page, "scheduled");
    await openDocumentsPane(page);

    const badge = page.locator('[data-testid="document-status"]');
    await expect(badge).toHaveText("Scheduled");

    // Polling runs every five seconds and picks up each server change.
    state.status = "extracting";
    await expect(badge).toHaveText("Extracting", { timeout: 10000 });
    state.status = "done";
    await expect(badge).toHaveText("Ready", { timeout: 10000 });
    await expect(badge).toHaveAttribute("data-status", "done");
  });

  test("failed document offers a retry that calls extract-document", async ({
    page,
  }) => {
    const state = await mockDocumentRoutes(page, "error");
    await openDocumentsPane(page);

    const badge = page.locator('[data-testid="document-status"]');
    await expect(badge).toHaveText("Failed");

    await page.getByRole("button", { name: "Retry Briefing.pdf" }).click();

    await expect.poll(() => state.extractCalls.length).toBe(1);
    expect(state.extractCalls[0]).toContain('"documentId":"doc-1"');
    await expect(badge).toHaveText("Extracting");
    state.status = "done";
    await expect(badge).toHaveText("Ready", { timeout: 10000 });
  });
});
