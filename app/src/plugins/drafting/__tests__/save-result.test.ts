import { describe, expect, it } from "vitest";
import { parseSaveResult } from "../save-result";

const SAVED = { version: 2, nodeId: "7", previewUrl: "/node/7/latest" };

describe("parseSaveResult", () => {
  it("reads a saved result from the live JSON string", () => {
    expect(parseSaveResult(JSON.stringify(SAVED))).toEqual({
      status: "saved",
      ...SAVED,
    });
  });

  it("reads a saved result from the reloaded object", () => {
    expect(parseSaveResult(SAVED)).toEqual({ status: "saved", ...SAVED });
  });

  it("reads an error as not saved, live and reloaded", () => {
    const error = "Draft 9 does not exist in this session.";
    const expected = { status: "not_saved", error };
    expect(parseSaveResult({ version: 9, error })).toEqual(expected);
    expect(parseSaveResult(JSON.stringify({ version: 9, error }))).toEqual(
      expected,
    );
  });

  it.each([
    [undefined],
    [[]],
    ["TOOL NOT EXECUTED. The user rejected this action."],
    [{ is_error: true, blocks: [] }],
    [{ version: 1, nodeId: "" }],
  ])("reads %j as cancelled", (input) => {
    expect(parseSaveResult(input)).toEqual({ status: "cancelled" });
  });
});
