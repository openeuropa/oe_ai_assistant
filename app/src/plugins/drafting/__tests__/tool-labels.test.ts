/**
 * Unit tests for the tool label reader.
 */

import { beforeEach, describe, expect, it } from "vitest";
import { setConfig } from "@/config";
import { toolLabel } from "../tool-labels";

describe("toolLabel", () => {
  beforeEach(() => {
    setConfig({
      userId: "1",
      sessionId: "1",
      pluginConfig: {
        drafting: {
          toolLabels: { drafting_get_history: "Get draft history" },
        },
      },
    });
  });

  it("reads the label the host declared", () => {
    expect(toolLabel("drafting_get_history")).toBe("Get draft history");
  });

  it("falls back to the tool name when the host declared none", () => {
    expect(toolLabel("drafting_draft_content")).toBe("drafting_draft_content");
  });
});
