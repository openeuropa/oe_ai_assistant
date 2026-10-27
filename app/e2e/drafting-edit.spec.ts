import { expect, type Page, test } from "@playwright/test";
import type {
  DraftFieldEdit,
  EditableDraft,
} from "../src/plugins/drafting/types";

const scalar = (type: string, extra = {}) => ({
  type: "array",
  items: { type: "object", properties: { value: { type, ...extra } } },
  maxItems: 1,
});
const schema = {
  type: "object",
  properties: {
    title: { ...scalar("string", { maxLength: 255 }), title: "Title" },
    field_teaser: {
      ...scalar("string", { "x-editor": "textarea" }),
      title: "Teaser",
    },
    body: {
      type: "array",
      title: "Body",
      maxItems: 1,
      items: {
        type: "object",
        properties: {
          value: { type: "string", "x-editor": "richtext" },
          summary: {
            oneOf: [{ type: "string" }, { type: "null" }],
            "x-editor": "textarea",
          },
        },
      },
    },
    field_date: { ...scalar("string", { format: "date" }), title: "Date" },
    field_time: {
      ...scalar("string", { format: "date-time" }),
      title: "Date and time",
    },
    field_kind: {
      ...scalar("string", {
        enum: ["news", "update"],
        "x-enum-labels": ["News", "Update"],
      }),
      title: "Kind",
    },
    field_count: { ...scalar("integer"), title: "Count" },
    field_featured: { ...scalar("boolean"), title: "Featured" },
    field_tags: {
      type: "array",
      items: { type: "object", properties: { value: { type: "string" } } },
      title: "Tags",
    },
    field_paragraphs: {
      type: "array",
      title: "Paragraphs",
      items: {
        type: "object",
        oneOf: [
          {
            type: "object",
            properties: {
              type: {
                type: "array",
                items: {
                  type: "object",
                  properties: { target_id: { type: "string", const: "quote" } },
                },
              },
              field_author: { ...scalar("string"), title: "Author" },
            },
          },
        ],
      },
    },
  },
};

async function mockDrafts(
  page: Page,
  { twoDrafts = false, saved = false } = {},
) {
  const fields = {
    title: [{ value: "Generated title" }],
    field_teaser: [],
    body: [
      {
        value: "<p>Original <strong>formatted</strong> content.</p>",
        format: "full_html",
        summary: null,
      },
    ],
    field_date: [{ value: "2026-10-27" }],
    field_time: [{ value: "2026-10-27T10:00:00+02:00" }],
    field_kind: [{ value: "news" }],
    field_count: [{ value: 3 }],
    field_featured: [{ value: false }],
    field_tags: [{ value: "First" }, { value: "Second" }],
    field_paragraphs: [
      {
        type: [{ target_id: "quote" }],
        field_author: [{ value: "Original author" }],
      },
    ],
  };
  const latest = twoDrafts ? 2 : 1;
  const drafts: Record<number, EditableDraft> = {
    1: { fields: structuredClone(fields), revision: 0, schema },
  };
  if (twoDrafts)
    drafts[2] = {
      fields: {
        ...structuredClone(fields),
        title: [{ value: "Second draft" }],
      },
      revision: 0,
      schema,
    };
  const savedCall = (version: number, revision: number) => ({
    role: "assistant",
    content: "",
    toolCalls: [
      {
        id: `saved-${version}-${revision}`,
        type: "function",
        function: {
          name: "save_draft",
          arguments: JSON.stringify({ version }),
        },
        result: {
          version,
          name: `Draft ${version}.0`,
          nodeId: "42",
          editRevision: revision,
        },
      },
    ],
  });
  const events: Record<string, unknown>[] = saved ? [savedCall(latest, 0)] : [];
  let pendingVersion: number | null = null;
  const pending = () =>
    pendingVersion === null
      ? []
      : [
          {
            role: "assistant",
            content: "",
            toolCalls: [
              {
                id: "save-pending",
                type: "function",
                function: {
                  name: "save_draft",
                  arguments: JSON.stringify({ version: pendingVersion }),
                },
              },
            ],
          },
        ];
  const stream = (events: unknown[]) =>
    events.map((event) => `data: ${JSON.stringify(event)}\n\n`).join("") +
    "data: [DONE]\n\n";
  const edits: DraftFieldEdit[] = [];
  let rejectNextEdit = false;
  await page.route("**/session/token", (route) =>
    route.fulfill({ body: "test-csrf" }),
  );
  await page.route("**/api/plugins/drafting/list-documents", (route) =>
    route.fulfill({ json: { documents: [] } }),
  );
  await page.route("**/api/plugins/drafting/get-messages", (route) =>
    route.fulfill({
      json: {
        messages: [
          ...Object.entries(drafts).map(([version, draft]) => ({
            role: "assistant",
            content: "",
            toolCalls: [
              {
                id: `draft-${version}`,
                type: "function",
                function: { name: "draft_group", arguments: "{}" },
                result: {
                  group: "main_fields",
                  draft: {
                    version: Number(version),
                    major: Number(version),
                    minor: 0,
                    editRevision: draft.revision,
                    fields: draft.fields,
                    context: { tone: null, template: null, documents: [] },
                  },
                },
              },
            ],
          })),
          ...events,
          ...pending(),
        ],
      },
    }),
  );
  await page.route("**/api/plugins/drafting/get-draft", (route) =>
    route.fulfill({ json: drafts[route.request().postDataJSON().version] }),
  );
  await page.route("**/api/plugins/drafting/edit-field", (route) => {
    const edit = route.request().postDataJSON() as DraftFieldEdit;
    edits.push(edit);
    const draft = drafts[edit.version];
    if (rejectNextEdit) {
      rejectNextEdit = false;
      draft.revision += 1;
      draft.fields.title = [{ value: "Updated in another tab" }];
      return route.fulfill({
        status: 409,
        json: {
          message:
            "This draft has changed. Reload the draft before applying your edit.",
        },
      });
    }
    let target = draft.fields[edit.field] as Record<string | number, unknown>;
    for (const key of edit.path.slice(0, -1)) {
      target[key] ??= {};
      target = target[key] as Record<string | number, unknown>;
    }
    target[edit.path[edit.path.length - 1]] = edit.value;
    draft.revision += 1;
    return route.fulfill({ json: draft });
  });
  await page.route("**/api/plugins/drafting/chat", (route) => {
    const body = route.request().postDataJSON();
    const message = JSON.stringify(body);
    pendingVersion = Number(message.match(/Save Draft (\d+)/)?.[1] ?? latest);
    return route.fulfill({
      contentType: "text/event-stream",
      headers: { "x-vercel-ai-ui-message-stream": "v1" },
      body: stream([
        { type: "start", messageId: "save-request" },
        {
          type: "data-approval-request",
          data: {
            approvals: [
              {
                id: "save-pending",
                name: "save_draft",
                inputs: { version: pendingVersion },
              },
            ],
          },
        },
        { type: "finish" },
      ]),
    });
  });
  await page.route("**/api/plugins/drafting/submit-approval", (route) => {
    if (pendingVersion !== null)
      events.push(savedCall(pendingVersion, drafts[pendingVersion].revision));
    pendingVersion = null;
    return route.fulfill({
      contentType: "text/event-stream",
      headers: { "x-vercel-ai-ui-message-stream": "v1" },
      body: stream([{ type: "start", messageId: "saved" }, { type: "finish" }]),
    });
  });
  await page.route("**/api/plugins/drafting/preview?**", (route) => {
    const version = Number(
      new URL(route.request().url()).searchParams.get("version"),
    );
    return route.fulfill({
      contentType: "text/html",
      body: `<h1>${(drafts[version].fields.title as { value: string }[])[0].value}</h1>`,
    });
  });
  await page.goto("/#/drafting");
  await page.getByRole("button", { name: "Data", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Edit Title", exact: true }),
  ).toBeVisible();
  return {
    edits,
    drafts,
    conflict: () => {
      rejectNextEdit = true;
    },
  };
}

async function editValue(page: Page, label: string, value: string) {
  await page
    .getByRole("button", { name: `Edit ${label}`, exact: true })
    .click();
  await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await expect(
    page.getByRole("button", { name: `Edit ${label}`, exact: true }),
  ).toBeEnabled();
}

test("applies and cancels edits, refreshes preview and survives reload", async ({
  page,
}) => {
  const { edits, drafts } = await mockDrafts(page);
  await page.getByRole("button", { name: "Edit Title", exact: true }).click();
  await page.getByLabel("Title", { exact: true }).fill("Cancelled title");
  await expect(
    page.getByRole("button", { name: "Save draft", exact: true }),
  ).toBeDisabled();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  expect(edits).toHaveLength(0);
  await editValue(page, "Title", "Editorial title");
  expect(edits[0]).toMatchObject({
    sessionId: "dev-session",
    version: 1,
    field: "title",
    path: [0, "value"],
    value: "Editorial title",
    revision: 0,
  });
  expect(drafts[1].fields.field_tags).toEqual([
    { value: "First" },
    { value: "Second" },
  ]);
  await page.getByRole("button", { name: "Live preview", exact: true }).click();
  await expect(
    page.frameLocator('iframe[title="Live preview"]').getByRole("heading"),
  ).toHaveText("Editorial title");
  await page.reload();
  await page.getByRole("button", { name: "Data", exact: true }).click();
  await expect(
    page.getByRole("row").filter({
      has: page.getByRole("button", { name: "Edit Title", exact: true }),
    }),
  ).toContainText("Editorial title");
});

test("uses typed inputs and preserves paths for repeated, empty and nested values", async ({
  page,
}) => {
  const { edits, drafts } = await mockDrafts(page);
  await editValue(page, "Teaser", "Filled empty value");
  await editValue(page, "Date", "2026-11-02");
  await editValue(page, "Date and time", "2026-11-02T09:15");
  await page.getByRole("button", { name: "Edit Kind", exact: true }).click();
  await page
    .getByRole("combobox", { name: "Kind" })
    .selectOption({ label: "Update" });
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await editValue(page, "Count", "7");
  await page
    .getByRole("button", { name: "Edit Featured", exact: true })
    .click();
  await page.getByRole("checkbox", { name: "Featured" }).check();
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await editValue(page, "Tags, item 2", "Updated second item");
  await editValue(page, "Paragraphs: Author", "Edited author");
  await page.getByRole("button", { name: "Edit Body", exact: true }).click();
  await page
    .getByRole("textbox", { name: "Body", exact: true })
    .fill("Edited rich text");
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Edit Body", exact: true }),
  ).toBeEnabled();
  expect(edits.find((edit) => edit.field === "field_count")?.value).toBe(7);
  expect(edits.find((edit) => edit.field === "field_time")?.value).toBe(
    "2026-11-02T09:15:00Z",
  );
  expect(edits.find((edit) => edit.field === "field_featured")?.value).toBe(
    true,
  );
  expect(edits.find((edit) => edit.field === "field_tags")?.path).toEqual([
    1,
    "value",
  ]);
  expect(edits.find((edit) => edit.field === "field_paragraphs")?.path).toEqual(
    [0, "field_author", 0, "value"],
  );
  await editValue(page, "Body: Summary", "Edited summary");
  expect(drafts[1].fields.body).toEqual([
    {
      value: "<p>Edited rich text</p>",
      format: "full_html",
      summary: "Edited summary",
    },
  ]);
  expect(drafts[1].fields.field_tags).toEqual([
    { value: "First" },
    { value: "Updated second item" },
  ]);
  await editValue(page, "Count", "");
  expect(edits.at(-1)?.value).toBeNull();
  await editValue(page, "Date", "");
  expect(edits.at(-1)?.value).toBeNull();
});

test("keeps rejected input and can reload the latest revision before retrying", async ({
  page,
}) => {
  const state = await mockDrafts(page);
  state.conflict();
  await page.getByRole("button", { name: "Edit Title", exact: true }).click();
  await page.getByLabel("Title", { exact: true }).fill("My correction");
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await expect(page.getByRole("alert")).toContainText("This draft has changed");
  await expect(page.getByLabel("Title", { exact: true })).toHaveValue(
    "My correction",
  );
  await page
    .getByRole("button", {
      name: "Load latest draft (keeps your input)",
      exact: true,
    })
    .click();
  await page.getByRole("button", { name: "Apply", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Edit Title", exact: true }),
  ).toBeEnabled();
  expect(state.edits[1].revision).toBe(1);
});

test("edits stay with their version and a saved draft can be saved again", async ({
  page,
}) => {
  await mockDrafts(page, { twoDrafts: true, saved: true });
  await expect(
    page.getByRole("button", { name: "Saved", exact: true }),
  ).toBeDisabled();
  await editValue(page, "Title", "Edited second draft");
  await expect(
    page.getByRole("button", { name: "Save draft", exact: true }),
  ).toBeEnabled();
  await page
    .getByRole("button", { name: "Open Draft 1.0", exact: true })
    .click();
  await expect(
    page.getByRole("row").filter({
      has: page.getByRole("button", { name: "Edit Title", exact: true }),
    }),
  ).toContainText("Generated title");
  await page
    .getByRole("button", { name: "Open Draft 2.0", exact: true })
    .click();
  await expect(
    page.getByRole("row").filter({
      has: page.getByRole("button", { name: "Edit Title", exact: true }),
    }),
  ).toContainText("Edited second draft");
  await page.getByRole("button", { name: "Save draft", exact: true }).click();
  await page
    .getByRole("button", { name: "Save as draft", exact: true })
    .click();
  await page.getByRole("button", { name: "Save it", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Saved", exact: true }),
  ).toBeDisabled();
});
