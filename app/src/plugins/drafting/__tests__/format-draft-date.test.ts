import { describe, expect, it } from "vitest";
import { formatDraftDate, formatDraftDateShort } from "../format-draft-date";

describe("formatDraftDate", () => {
  it("renders weekday, day, month, year and 24h time", () => {
    // Local-time constructor so the expectation holds in any zone.
    const date = new Date(2026, 4, 22, 14, 30);
    expect(formatDraftDate(date)).toBe("Friday, 22 May 2026 at 14:30");
  });

  it("zero-pads the hour", () => {
    const date = new Date(2026, 0, 5, 9, 5);
    expect(formatDraftDate(date)).toBe("Monday, 5 January 2026 at 09:05");
  });
});

describe("formatDraftDateShort", () => {
  it("renders short weekday and month with an unpadded hour", () => {
    // Local-time constructor so the expectation holds in any zone.
    const date = new Date(2026, 10, 9, 9, 40);
    expect(formatDraftDateShort(date)).toBe("Mon, 9 Nov 2026, at 9:40");
  });

  it("zero-pads the minute", () => {
    const date = new Date(2026, 4, 22, 14, 5);
    expect(formatDraftDateShort(date)).toBe("Fri, 22 May 2026, at 14:05");
  });
});
