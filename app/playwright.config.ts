import { defineConfig } from "@playwright/test";

/**
 * Playwright configuration for E2E tests.
 *
 * Tests run against the Vite dev server (port 5173) which proxies
 * /api requests to the Express mock server (port 5150). Both are
 * started by `npm run dev`.
 */
export default defineConfig({
  testDir: "./e2e",
  timeout: 30000,
  use: {
    baseURL: "http://localhost:5173",
    headless: true,
  },
  // The dev server must be running before tests.
  // Use: npm run dev & npx playwright test
  webServer: {
    command: "npm run dev",
    port: 5173,
    reuseExistingServer: true,
    timeout: 15000,
  },
});
