import { describe, expect, it } from "vitest";
import { extractParticipants } from "../participants";

/** Builds a user message shape carrying an author name. */
function authored(userName: string) {
  return { metadata: { custom: { userName } } };
}

describe("extractParticipants", () => {
  it("returns an empty list for an empty thread", () => {
    expect(extractParticipants([])).toEqual([]);
  });

  it("lists contributors in order of their first message, deduplicated", () => {
    const messages = [
      authored("Maria Rossi"),
      {},
      authored("Jan Kowalski"),
      authored("Maria Rossi"),
      authored("Admin"),
    ];

    expect(extractParticipants(messages)).toEqual([
      "Maria Rossi",
      "Jan Kowalski",
      "Admin",
    ]);
  });

  it("ignores blank names", () => {
    const messages = [authored("  "), authored("Maria Rossi")];

    expect(extractParticipants(messages)).toEqual(["Maria Rossi"]);
  });
});
