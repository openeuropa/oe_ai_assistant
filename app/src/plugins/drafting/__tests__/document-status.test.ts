import { describe, expect, it } from "vitest";
import {
  countUnsettled,
  describeDocumentStatus,
  isDocumentSettled,
} from "../document-status";

describe("describeDocumentStatus", () => {
  it.each([
    ["scheduled", "Scheduled", true],
    ["extracting", "Extracting", true],
    ["extracted", "Extracted", true],
    ["summarizing", "Summarizing", true],
    ["done", "Ready", false],
    ["error", "Failed", false],
  ] as const)("describes %s", (status, label, busy) => {
    const description = describeDocumentStatus(status);
    expect(description.label).toBe(label);
    expect(description.busy).toBe(busy);
  });

  it("gives final states distinct tones", () => {
    expect(describeDocumentStatus("done").tone).toBe("success");
    expect(describeDocumentStatus("error").tone).toBe("danger");
    expect(describeDocumentStatus("extracting").tone).toBe("progress");
  });
});

describe("isDocumentSettled", () => {
  it("is true only for done and error", () => {
    expect(isDocumentSettled("done")).toBe(true);
    expect(isDocumentSettled("error")).toBe(true);
    expect(isDocumentSettled("scheduled")).toBe(false);
    expect(isDocumentSettled("summarizing")).toBe(false);
  });
});

describe("countUnsettled", () => {
  it("counts documents the pipeline still owns", () => {
    expect(
      countUnsettled([
        { status: "done" },
        { status: "scheduled" },
        { status: "error" },
        { status: "summarizing" },
      ]),
    ).toBe(2);
    expect(countUnsettled([])).toBe(0);
  });
});
