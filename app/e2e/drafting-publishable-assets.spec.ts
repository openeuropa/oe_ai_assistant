import { randomUUID } from "node:crypto";
import { test as base, expect } from "@playwright/test";
import type { components } from "../src/api/schema";
import { developmentConfig } from "../src/development-config";

type Category = components["schemas"]["DraftingDocumentCategory"];
type Document = components["schemas"]["DraftingDocument"];

const categories: Category[] = ["context", "publishable"];
const apiPath = "/api/plugins/drafting";
const seedFiles: { category: Category; filename: string }[] = [
  { category: "context", filename: "EU AI Act briefing note.pdf" },
  { category: "context", filename: "Stakeholder comments.docx" },
  { category: "publishable", filename: "European Commission building.jpg" },
  { category: "publishable", filename: "AI Act factsheet.pdf" },
];

const test = base.extend<{ assetSession: string }>({
  assetSession: [
    async ({ page, request }, use) => {
      const sessionId = `publishable-assets-e2e-${randomUUID()}`;

      // Give the real app its own host configuration, including after reload.
      // The shared development session and production startup stay untouched.
      await page.route("**/src/development-config.ts", (route) =>
        route.fulfill({
          contentType: "application/javascript",
          body: `export const developmentConfig = ${JSON.stringify({
            ...developmentConfig,
            sessionId,
          })};`,
        }),
      );

      async function listDocuments(category: Category): Promise<Document[]> {
        const response = await request.post(`${apiPath}/list-documents`, {
          data: { sessionId, category },
        });
        expect(response.ok()).toBe(true);
        return (await response.json()).documents;
      }

      try {
        // Use the mock API's actual upload and processing paths to seed this
        // session; no assumptions about server startup fixtures are needed.
        await Promise.all(
          seedFiles.map(async ({ category, filename }) => {
            const query = new URLSearchParams({
              sessionId,
              category,
              filename,
            });
            const response = await request.post(
              `${apiPath}/add-document?${query}`,
              {
                headers: { "Content-Type": "application/octet-stream" },
                data: Buffer.from(`Mock contents of ${filename}`),
              },
            );
            expect(response.ok()).toBe(true);
            const { document } = await response.json();
            const extraction = await request.post(
              `${apiPath}/extract-document`,
              {
                data: { sessionId, category, documentId: document.id },
              },
            );
            expect(extraction.ok()).toBe(true);
          }),
        );

        await expect
          .poll(
            async () => {
              const lists = await Promise.all(categories.map(listDocuments));
              return lists
                .flat()
                .every((document) => document.status === "done");
            },
            { timeout: 15000, intervals: [500, 1000] },
          )
          .toBe(true);

        await use(sessionId);
      } finally {
        // Include files uploaded by the browser, even if an assertion or setup
        // step failed before the test could remove them through the panel.
        for (const category of categories) {
          const documents = await listDocuments(category);
          for (const document of documents) {
            const response = await request.post(`${apiPath}/remove-document`, {
              data: { sessionId, category, documentId: document.id },
            });
            expect(response.ok()).toBe(true);
          }
        }
      }
    },
    { auto: true },
  ],
});

test("publishable assets stay separate through upload, reload, and removal", async ({
  page,
}) => {
  await page.goto("/#/drafting");

  const contextButton = page.getByRole("button", { name: /Context documents/ });
  const assetsButton = page.getByRole("button", { name: /Publishable assets/ });
  await expect(contextButton).toContainText("2 documents");
  await expect(assetsButton).toContainText("2 assets");
  await assetsButton.click();

  const assetsPanel = page.getByRole("dialog", {
    name: "Publishable assets",
    exact: true,
  });
  await expect(
    assetsPanel.getByText("European Commission building.jpg", { exact: true }),
  ).toBeVisible();
  await expect(
    assetsPanel.getByText("AI Act factsheet.pdf", { exact: true }),
  ).toBeVisible();
  await expect(
    assetsPanel.getByText("EU AI Act briefing note.pdf", { exact: true }),
  ).toHaveCount(0);
  await expect(
    assetsPanel.getByText(/may include or link in the generated content/),
  ).toBeVisible();
  await expect(assetsPanel.getByText(/private, never published/)).toHaveCount(
    0,
  );

  const fileInput = assetsPanel.getByLabel("Upload publishable assets");
  await expect(fileInput).toHaveAttribute("accept", /\.jpg/);
  const filename = `Publishable image ${Date.now()}.jpg`;
  await fileInput.setInputFiles({
    name: filename,
    mimeType: "image/jpeg",
    buffer: Buffer.from("mock image upload"),
  });
  await expect(assetsPanel.getByText(filename, { exact: true })).toBeVisible();
  await assetsPanel.getByRole("button", { name: "Close", exact: true }).click();
  await expect(assetsButton).toContainText(/Processing 1 of 3|3 assets/);
  await expect(contextButton).toContainText("2 documents");

  // The list is reloaded from the mock API, rather than retained only in React.
  await page.reload();
  await assetsButton.click();
  await expect(assetsPanel.getByText(filename, { exact: true })).toBeVisible();
  await assetsPanel
    .getByRole("button", { name: `Remove ${filename}`, exact: true })
    .click();
  const confirmation = page.getByRole("dialog", {
    name: "Remove publishable asset",
    exact: true,
  });
  await expect(confirmation.getByText(/future draft/)).toBeVisible();
  await confirmation
    .getByRole("button", { name: "Remove asset", exact: true })
    .click();
  await expect(confirmation).toBeHidden();
  await expect(assetsPanel.getByText(filename, { exact: true })).toHaveCount(0);
  await assetsPanel.getByRole("button", { name: "Close", exact: true }).click();
  await expect(assetsButton).toContainText("2 assets");

  await contextButton.click();
  const contextPanel = page.getByRole("dialog", {
    name: "Context documents",
    exact: true,
  });
  await expect(
    contextPanel.getByText("EU AI Act briefing note.pdf", { exact: true }),
  ).toBeVisible();
  await expect(
    contextPanel.getByText("Stakeholder comments.docx", { exact: true }),
  ).toBeVisible();
  await expect(
    contextPanel.getByText("AI Act factsheet.pdf", { exact: true }),
  ).toHaveCount(0);
});

test("empty publishable assets have their own empty state and count", async ({
  page,
}) => {
  await page.route("**/api/plugins/drafting/list-documents", (route) => {
    const { category } = route.request().postDataJSON();
    return category === "publishable"
      ? route.fulfill({ json: { documents: [] } })
      : route.continue();
  });
  await page.goto("/#/drafting");
  const assetsButton = page.getByRole("button", { name: /Publishable assets/ });
  await expect(assetsButton).toContainText("0 assets");
  await assetsButton.click();
  await expect(
    page.getByText(
      "No publishable assets are attached to this drafting session.",
      { exact: true },
    ),
  ).toBeVisible();
});
