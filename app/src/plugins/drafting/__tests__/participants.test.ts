import { describe, expect, it } from "vitest";
import { extractParticipants, resolveMessageAuthor } from "../participants";

/** The current user handed to the extraction helpers in these tests. */
const CURRENT_USER = { id: "1", name: "Admin" };

/** Builds a rehydrated user message shape carrying author metadata. */
function authored(userName: string, userId?: string) {
  return {
    role: "user",
    metadata: { custom: { userName, ...(userId ? { userId } : {}) } },
  };
}

describe("extractParticipants", () => {
  it("returns an empty list for an empty thread", () => {
    expect(extractParticipants([], CURRENT_USER)).toEqual([]);
  });

  it("lists contributors in order of their first message, deduplicated", () => {
    const messages = [
      authored("Maria Rossi", "2"),
      { role: "assistant" },
      authored("Jan Kowalski", "3"),
      authored("Maria Rossi", "2"),
      authored("Admin", "1"),
    ];

    expect(extractParticipants(messages, CURRENT_USER)).toEqual([
      { id: "2", name: "Maria Rossi" },
      { id: "3", name: "Jan Kowalski" },
      { id: "1", name: "Admin" },
    ]);
  });

  it("keeps two users with the same display name apart by id", () => {
    const messages = [
      authored("Maria Rossi", "2"),
      authored("Maria Rossi", "7"),
    ];

    expect(extractParticipants(messages, CURRENT_USER)).toEqual([
      { id: "2", name: "Maria Rossi" },
      { id: "7", name: "Maria Rossi" },
    ]);
  });

  it("attributes live user turns without metadata to the current user", () => {
    const messages = [
      authored("Maria Rossi", "2"),
      // A turn sent live in this browser carries no author metadata.
      { role: "user" },
    ];

    expect(extractParticipants(messages, CURRENT_USER)).toEqual([
      { id: "2", name: "Maria Rossi" },
      { id: "1", name: "Admin" },
    ]);
  });

  it("ignores blank names and metadata-less assistant turns", () => {
    const messages = [
      authored("  "),
      { role: "assistant" },
      authored("Maria Rossi", "2"),
    ];

    expect(extractParticipants(messages, { id: "1", name: "" })).toEqual([
      { id: "2", name: "Maria Rossi" },
    ]);
  });
});

describe("resolveMessageAuthor", () => {
  it("prefers the metadata author over the current user", () => {
    expect(
      resolveMessageAuthor(authored("Maria Rossi", "2"), CURRENT_USER),
    ).toEqual({ id: "2", name: "Maria Rossi" });
  });

  it("attributes a metadata-less user turn to the current user", () => {
    expect(resolveMessageAuthor({ role: "user" }, CURRENT_USER)).toEqual({
      id: "1",
      name: "Admin",
    });
  });

  it("returns null for assistant turns without metadata", () => {
    expect(
      resolveMessageAuthor({ role: "assistant" }, CURRENT_USER),
    ).toBeNull();
  });
});
