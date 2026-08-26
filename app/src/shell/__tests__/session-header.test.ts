import { describe, expect, it } from "vitest";
import { avatarColorClass } from "@/components/ui/user-avatar";
import { buildAvatarStackItems } from "../session-header";

/** The current user handed to the helper in these tests. */
const CURRENT_USER = { id: "1", name: "Admin" };

describe("buildAvatarStackItems", () => {
  it("gives the current user the first palette color in a fresh session", () => {
    // No contributors yet: the current user will join at position 0, so
    // they get that color provisionally instead of the neutral gray.
    expect(buildAvatarStackItems([], CURRENT_USER)).toEqual([
      { id: "1", name: "Admin", colorClass: avatarColorClass(0) },
    ]);
  });

  it("boosts the current user to the front while colors keep contribution order", () => {
    const contributors = [
      { id: "2", name: "Maria Rossi" },
      { id: "1", name: "Admin" },
    ];

    expect(buildAvatarStackItems(contributors, CURRENT_USER)).toEqual([
      { id: "1", name: "Admin", colorClass: avatarColorClass(1) },
      { id: "2", name: "Maria Rossi", colorClass: avatarColorClass(0) },
    ]);
  });

  it("colors a not-yet-contributing current user with the next free color", () => {
    const contributors = [{ id: "2", name: "Maria Rossi" }];

    expect(buildAvatarStackItems(contributors, CURRENT_USER)).toEqual([
      { id: "1", name: "Admin", colorClass: avatarColorClass(1) },
      { id: "2", name: "Maria Rossi", colorClass: avatarColorClass(0) },
    ]);
  });

  it("shows only contributors when the current user has no name", () => {
    const contributors = [{ id: "2", name: "Maria Rossi" }];

    expect(buildAvatarStackItems(contributors, { id: "1", name: "" })).toEqual([
      { id: "2", name: "Maria Rossi", colorClass: avatarColorClass(0) },
    ]);
  });
});
