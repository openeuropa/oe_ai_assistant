/**
 * Unit tests for the draft result parser.
 *
 * Covers the versioned shape (full, with nulls), the legacy flat map, and
 * degenerate inputs (null, non-object).
 */

import { describe, expect, it } from "vitest";
import { parseDraftResult } from "../draft-result";

describe("parseDraftResult", () => {
  it("parses the versioned shape with full context", () => {
    const raw = {
      version: 1,
      context: {
        tone: { id: "formal", label: "Formal", prompt: "Be formal." },
        template: { id: "news", label: "News Article" },
        documents: [
          {
            id: "doc-1",
            title: "Briefing",
            category: "context",
            summary: "A summary.",
            meta: { pages: 3 },
          },
          {
            id: "doc-2",
            title: "Press release",
            category: "publishable",
          },
        ],
      },
      fields: { title: [{ value: "My Title" }], body: [{ value: "Body." }] },
    };

    const result = parseDraftResult(raw);

    expect(result.version).toBe(1);
    expect(result.context).not.toBeNull();
    expect(result.context?.tone).toEqual({
      id: "formal",
      label: "Formal",
      prompt: "Be formal.",
    });
    expect(result.context?.template).toEqual({
      id: "news",
      label: "News Article",
    });
    expect(result.context?.documents).toHaveLength(2);
    expect(result.context?.documents[0]).toEqual({
      id: "doc-1",
      title: "Briefing",
      category: "context",
      summary: "A summary.",
      meta: { pages: 3 },
    });
    expect(result.context?.documents[1]).toEqual({
      id: "doc-2",
      title: "Press release",
      category: "publishable",
    });
    expect(result.fields).toEqual(raw.fields);
  });

  it("parses versioned shape with null tone, null template, empty documents", () => {
    const raw = {
      version: 2,
      context: {
        tone: null,
        template: null,
        documents: [],
      },
      fields: { title: [{ value: "Only title" }] },
    };

    const result = parseDraftResult(raw);

    expect(result.version).toBe(2);
    expect(result.context?.tone).toBeNull();
    expect(result.context?.template).toBeNull();
    expect(result.context?.documents).toEqual([]);
    expect(result.fields).toEqual(raw.fields);
  });

  it("falls back null/missing context fields to null/empty-array", () => {
    const raw = {
      version: 1,
      // No context key at all.
      fields: { body: [{ value: "Text" }] },
    };

    const result = parseDraftResult(raw);

    expect(result.version).toBe(1);
    expect(result.context?.tone).toBeNull();
    expect(result.context?.template).toBeNull();
    expect(result.context?.documents).toEqual([]);
  });

  it("treats a flat object without numeric version as a legacy fields map", () => {
    const raw = {
      title: [{ value: "Legacy Title" }],
      body: [{ value: "Legacy body." }],
    };

    const result = parseDraftResult(raw);

    expect(result.version).toBeNull();
    expect(result.context).toBeNull();
    expect(result.fields).toEqual(raw);
  });

  it("treats an object with a non-numeric version as a legacy fields map", () => {
    const raw = { version: "v1", title: [{ value: "Old" }] };

    const result = parseDraftResult(raw);

    expect(result.version).toBeNull();
    expect(result.context).toBeNull();
    expect(result.fields).toEqual(raw);
  });

  it("returns empty fields for null input", () => {
    const result = parseDraftResult(null);

    expect(result.version).toBeNull();
    expect(result.context).toBeNull();
    expect(result.fields).toEqual({});
  });

  it("returns empty fields for non-object input", () => {
    const result = parseDraftResult("not an object");

    expect(result.version).toBeNull();
    expect(result.context).toBeNull();
    expect(result.fields).toEqual({});
  });

  it("returns empty fields for undefined input", () => {
    const result = parseDraftResult(undefined);

    expect(result.version).toBeNull();
    expect(result.context).toBeNull();
    expect(result.fields).toEqual({});
  });
});
