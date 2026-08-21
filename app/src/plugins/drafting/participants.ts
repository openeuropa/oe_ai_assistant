/**
 * Session participants derived from the assistant-ui thread.
 *
 * Every user message can carry the author's display name in its custom
 * metadata (persisted transcripts provide it via hydration). The
 * participants list follows the order in which each author's FIRST
 * message appears in the transcript, so it is identical for every
 * viewer of the session and the palette colors derived from it never
 * shift across browsers. The reporter publishes the list to the shell
 * store so the session header can render the avatar stack while staying
 * plugin agnostic.
 */

import { useAuiState } from "@assistant-ui/react";
import { useEffect, useRef } from "react";
import { getConfig } from "@/config";
import { useAppStore } from "@/store";

/** Minimal shape of a thread message this module inspects. */
interface MessageLikeShape {
  role?: string;
  metadata?: { custom?: Record<string, unknown> };
}

/**
 * Extracts the contributors in the order of their first message in the
 * transcript, deduplicated. User turns without author metadata are
 * turns sent live in this browser (only rehydrated messages carry the
 * name), so they are attributed to the current user; the backend
 * records the same author, keeping the order identical after reloads
 * and in other browsers.
 */
export function extractParticipants(
  messages: readonly MessageLikeShape[],
  currentUserName: string,
): string[] {
  const contributors: string[] = [];

  for (const message of messages) {
    const metadataName = message.metadata?.custom?.["userName"];
    const name =
      typeof metadataName === "string" && metadataName.trim() !== ""
        ? metadataName
        : message.role === "user"
          ? currentUserName.trim()
          : "";
    if (name !== "" && !contributors.includes(name)) {
      contributors.push(name);
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
    const participants = extractParticipants(
      messages as readonly MessageLikeShape[],
      getConfig().userName,
    );
    // Only publish when the list actually changed to avoid store churn.
    const key = participants.join("|");
    if (key !== lastPublished.current) {
      lastPublished.current = key;
      setSessionParticipants(participants);
    }
  }, [messages, setSessionParticipants]);
}
