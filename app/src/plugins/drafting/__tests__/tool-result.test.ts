import { describe, expect, it } from "vitest";
import { decodeToolResult } from "../tool-result";

describe("decodeToolResult", () => {
  it("reads the text a tool returned", () => {
    expect(decodeToolResult('{"version":2,"nodeId":"7"}')).toEqual({
      version: 2,
      nodeId: "7",
    });
  });

  it("passes an already decoded result through", () => {
    const result = { version: 2 };
    expect(decodeToolResult(result)).toBe(result);
  });

  it("returns null for a call that has not answered", () => {
    expect(decodeToolResult(undefined)).toBeNull();
    expect(decodeToolResult(null)).toBeNull();
  });

  it("returns null for text that is not an object", () => {
    expect(decodeToolResult("not json")).toBeNull();
    expect(decodeToolResult('"a string"')).toBeNull();
    expect(decodeToolResult("42")).toBeNull();
  });
});
