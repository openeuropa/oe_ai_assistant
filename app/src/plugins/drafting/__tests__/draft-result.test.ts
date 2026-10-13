/**
 * Unit tests for the draft result parser.
 *
 * Covers the stored shape (full, with nulls), drafts missing a number, and
 * degenerate inputs (null, non-object).
 */

import { describe, expect, it } from "vitest";
import { parseDraftResult } from "../draft-result";

describe("parseDraftResult", () => {
  it("parses the stored shape with full context", () => {
    const raw = {
      version: 1,
      major: 1,
      minor: 0,
      context: {
        tone: { id: "formal", label: "Formal", prompt: "Be formal." },
        template: { id: "news", label: "News Article" },
        documents: [
          {
            id: "doc-1",
            title: "Briefing",
            status: "done",
            meta: { type: "pdf", size: 1024 },
            category: "context",
            summary: "A summary.",
          },
          {
            id: "doc-2",
            title: "Press release",
            status: "scheduled",
            meta: { type: "docx", size: 2048 },
            category: "context",
            summary: "",
          },
        ],
      },
      fields: { title: [{ value: "My Title" }], body: [{ value: "Body." }] },
    };

    const result = parseDraftResult(raw);

    expect(result?.version).toBe(1);
    expect(result?.major).toBe(1);
    expect(result?.minor).toBe(0);
    expect(result?.context.tone).toEqual({
      id: "formal",
      label: "Formal",
      prompt: "Be formal.",
    });
    expect(result?.context.template).toEqual({
      id: "news",
      label: "News Article",
    });
    expect(result?.context.documents).toHaveLength(2);
    expect(result?.context.documents).toEqual(raw.context.documents);
    expect(result?.fields).toEqual(raw.fields);
  });

  it("parses the stored shape with null tone, null template, empty documents", () => {
    const raw = {
      version: 3,
      major: 2,
      minor: 1,
      context: {
        tone: null,
        template: null,
        documents: [],
      },
      fields: { title: [{ value: "Only title" }] },
    };

    const result = parseDraftResult(raw);

    expect(result?.version).toBe(3);
    expect(result?.major).toBe(2);
    expect(result?.minor).toBe(1);
    expect(result?.context.tone).toBeNull();
    expect(result?.context.template).toBeNull();
    expect(result?.context.documents).toEqual([]);
    expect(result?.fields).toEqual(raw.fields);
  });

  it("falls back null/missing context fields to null/empty-array", () => {
    const raw = {
      version: 1,
      major: 1,
      minor: 0,
      // No context key at all.
      fields: { body: [{ value: "Text" }] },
    };

    const result = parseDraftResult(raw);

    expect(result?.version).toBe(1);
    expect(result?.context.tone).toBeNull();
    expect(result?.context.template).toBeNull();
    expect(result?.context.documents).toEqual([]);
  });

  it("returns null for a draft without major and minor", () => {
    const raw = { version: 1, fields: { title: [{ value: "Unnumbered" }] } };

    expect(parseDraftResult(raw)).toBeNull();
  });

  it("returns null for a flat fields map", () => {
    const raw = {
      title: [{ value: "Flat Title" }],
      body: [{ value: "Flat body." }],
    };

    expect(parseDraftResult(raw)).toBeNull();
  });

  it("returns null for a non-numeric version", () => {
    const raw = { version: "v1", major: 1, minor: 0, fields: {} };

    expect(parseDraftResult(raw)).toBeNull();
  });

  it("returns null for null, undefined and non-object input", () => {
    expect(parseDraftResult(null)).toBeNull();
    expect(parseDraftResult(undefined)).toBeNull();
    expect(parseDraftResult("not an object")).toBeNull();
  });
});
