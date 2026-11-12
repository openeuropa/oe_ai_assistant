import { expect, test } from "@playwright/test";

/**
 * E2E tests for the transparency notice under the drafting composer.
 *
 * The notice comes from the development config, which contains a link. The
 * API routes the drafting page calls on load are mocked via page.route().
 */

/** Registers the routes the drafting page calls on load. */
async function mockApiRoutes(page: import("@playwright/test").Page) {
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
}

test.describe("Transparency notice", () => {
  test("notice links open in a new tab", async ({ page }) => {
    await mockApiRoutes(page);
    await page.goto("/#/drafting");

    const link = page.getByRole("link", { name: "Read our AI policy" });
    await expect(link).toHaveAttribute("href", "https://example.com/ai-policy");
    await expect(link).toHaveAttribute("target", "_blank");
    await expect(link).toHaveAttribute("rel", "noopener");
  });
});
