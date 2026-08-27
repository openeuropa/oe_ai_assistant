/**
 * Session participants derived from the assistant-ui thread.
 *
 * Every user message can carry the author's display name and user id in
 * its custom metadata (persisted transcripts provide them via
 * hydration; the contract guarantees they travel as a pair).
 * Participants are keyed by the user id so two users who share a
 * display name stay distinct. The participants list follows the
 * order in which each author's FIRST message appears in the transcript,
 * so it is identical for every viewer of the session and the palette
 * colors derived from it never shift across browsers. The reporter
 * publishes the list to the shell store so the session header can
 * render the avatar stack while staying plugin agnostic.
 */

import { useAuiState } from "@assistant-ui/react";
import { useEffect, useRef } from "react";
import { getConfig } from "@/config";
import { type SessionParticipant, useAppStore } from "@/store";

/** Minimal shape of a thread message this module inspects. */
interface MessageLikeShape {
  role?: string;
  metadata?: { custom?: Record<string, unknown> };
}

/** Reads a non-blank string value from the custom metadata. */
function metadataString(
  custom: Record<string, unknown> | undefined,
  key: string,
): string | null {
  const value = custom?.[key];
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

/**
 * Resolves the author of a thread message.
 *
 * Rehydrated messages carry the author in their custom metadata; the
 * contract guarantees the name and the user id travel as a pair. User
 * turns without author metadata are turns sent live in this browser, so
 * they are attributed to the current user; the backend records the same
 * author, keeping the result identical after reloads and in other
 * browsers. Returns null when no author can be determined (e.g.
 * assistant turns).
 */
export function resolveMessageAuthor(
  message: MessageLikeShape,
  currentUser: SessionParticipant,
): SessionParticipant | null {
  const custom = message.metadata?.custom;
  const name = metadataString(custom, "userName");
  const id = metadataString(custom, "userId");
  if (name !== null && id !== null) {
    return { id, name };
  }
  if (message.role === "user" && currentUser.name.trim() !== "") {
    return { id: currentUser.id, name: currentUser.name.trim() };
  }
  return null;
}

/**
 * Extracts the contributors in the order of their first message in the
 * transcript, deduplicated by user id.
 */
export function extractParticipants(
  messages: readonly MessageLikeShape[],
  currentUser: SessionParticipant,
): SessionParticipant[] {
  const contributors: SessionParticipant[] = [];

  for (const message of messages) {
    const author = resolveMessageAuthor(message, currentUser);
    if (author && !contributors.some((p) => p.id === author.id)) {
      contributors.push(author);
    }
  }

  return contributors;
}

/**
 * Publishes the participants to the shell store whenever the derived
 * list changes. Must be called inside the AssistantRuntimeProvider.
 */
export function useReportParticipants(): void {
  const messages = useAuiState((s) => s.thread.messages);
  const setSessionParticipants = useAppStore((s) => s.setSessionParticipants);
  const lastPublished = useRef("");

  useEffect(() => {
    const config = getConfig();
    const participants = extractParticipants(
      messages as readonly MessageLikeShape[],
      { id: config.userId, name: config.userName },
    );
    // Only publish when the list actually changed to avoid store churn.
    const key = participants.map((p) => `${p.id}:${p.name}`).join("|");
    if (key !== lastPublished.current) {
      lastPublished.current = key;
      setSessionParticipants(participants);
    }
  }, [messages, setSessionParticipants]);
}
