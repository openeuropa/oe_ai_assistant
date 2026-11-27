import { describe, expect, it } from "vitest";
import { parseSaveResult, readSaveState } from "../save-result";

const SAVED = { version: "2", nodeId: "7", previewUrl: "/node/7/latest" };

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
    expect(parseSaveResult({ version: "9", error })).toEqual(expected);
    expect(parseSaveResult(JSON.stringify({ version: "9", error }))).toEqual(
      expected,
    );
  });

  it("reads an interrupted call as not saved, with the text it carries", () => {
    expect(
      parseSaveResult({
        is_error: true,
        blocks: [
          { type: "text", text: "The call was interrupted before its result" },
          { type: "text", text: " was recorded." },
        ],
      }),
    ).toEqual({
      status: "not_saved",
      error: "The call was interrupted before its result was recorded.",
    });
  });

  it("reads a bare string error as not saved", () => {
    const error = "version must be of type integer, string given.";
    expect(parseSaveResult(error)).toEqual({ status: "not_saved", error });
  });

  it("reads the cancel the store records as cancelled", () => {
    expect(
      parseSaveResult({
        cancelled: true,
        message: "The editor cancelled this save, so nothing was written.",
      }),
    ).toEqual({ status: "cancelled" });
  });

  it("reads the cancel sentence as cancelled even as a bare string", () => {
    expect(
      parseSaveResult(
        "The editor cancelled this save, so nothing was written. A later request to save is a new request.",
      ),
    ).toEqual({ status: "cancelled" });
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

describe("readSaveState", () => {
  it("reads a call the server marked as awaiting a decision", () => {
    expect(
      readSaveState({ version: "3", awaitingDecision: true }, undefined, false),
    ).toEqual({ status: "waiting", version: "3" });
  });

  it("reads a call with no result that is still streaming as running", () => {
    expect(readSaveState({ version: "3" }, undefined, true)).toEqual({
      status: "running",
    });
  });

  it("reads a call left with no result once the reply ended as waiting", () => {
    expect(readSaveState({ version: "3" }, undefined, false)).toEqual({
      status: "waiting",
      version: "3",
    });
  });

  it("prefers the result over the awaiting mark", () => {
    expect(
      readSaveState({ version: "2", awaitingDecision: true }, SAVED, false),
    ).toEqual({ status: "saved", ...SAVED });
  });

  it("reads a waiting call whose version is unknown as an empty version", () => {
    expect(readSaveState({}, undefined, false)).toEqual({
      status: "waiting",
      version: "",
    });
  });
});
