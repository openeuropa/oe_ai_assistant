import { describe, expect, it } from "vitest";
import { extractParticipants } from "../participants";

/** Builds a rehydrated user message shape carrying an author name. */
function authored(userName: string) {
  return { role: "user", metadata: { custom: { userName } } };
}

describe("extractParticipants", () => {
  it("returns an empty list for an empty thread", () => {
    expect(extractParticipants([], "Admin")).toEqual([]);
  });

  it("lists contributors in order of their first message, deduplicated", () => {
    const messages = [
      authored("Maria Rossi"),
      { role: "assistant" },
      authored("Jan Kowalski"),
      authored("Maria Rossi"),
      authored("Admin"),
    ];

    expect(extractParticipants(messages, "Admin")).toEqual([
      "Maria Rossi",
      "Jan Kowalski",
      "Admin",
    ]);
  });

  it("attributes live user turns without metadata to the current user", () => {
    const messages = [
      authored("Maria Rossi"),
      // A turn sent live in this browser carries no author metadata.
      { role: "user" },
    ];

    expect(extractParticipants(messages, "Admin")).toEqual([
      "Maria Rossi",
      "Admin",
    ]);
  });

  it("ignores blank names and metadata-less assistant turns", () => {
    const messages = [
      authored("  "),
      { role: "assistant" },
      authored("Maria Rossi"),
    ];

    expect(extractParticipants(messages, "")).toEqual(["Maria Rossi"]);
  });
});
