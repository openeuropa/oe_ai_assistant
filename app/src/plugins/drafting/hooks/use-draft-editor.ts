import { useAuiState } from "@assistant-ui/react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";
import { getConfig } from "@/config";
import { useAppStore } from "@/store";
import { editDraftField, getEditableDraft } from "../api/drafting-api";
import { getDraftingState, setDraftingState } from "../store";
import type { DraftFieldEdit, EditableDraft } from "../types";

/** Keeps server-confirmed edits consistent across the pane, cards and rail. */
export function useDraftEditor(version: number | null) {
  const isRunning = useAuiState((state) => state.thread.isRunning);
  const client = useQueryClient();
  const key = ["draft-editor", getConfig().sessionId, version];
  const query = useQuery({
    queryKey: key,
    queryFn: () => getEditableDraft(version as number),
    enabled: version !== null && !isRunning,
    refetchOnWindowFocus: false,
    retry: false,
  });

  useEffect(() => {
    if (query.data && version !== null) acceptDraft(version, query.data);
  }, [query.data, version]);

  async function apply(edit: Omit<DraftFieldEdit, "version" | "revision">) {
    if (version === null || !query.data)
      throw new Error("This draft cannot be edited.");
    useAppStore.getState().setPendingWork("drafting:apply-edit", true);
    try {
      const draft = await editDraftField({
        ...edit,
        version,
        revision: query.data.revision,
      });
      client.setQueryData(key, draft);
      acceptDraft(version, draft);
      return draft.revision !== query.data.revision;
    } finally {
      useAppStore.getState().setPendingWork("drafting:apply-edit", false);
    }
  }

  return { ...query, apply, isRunning };
}

function acceptDraft(version: number, draft: EditableDraft) {
  const current = getDraftingState();
  setDraftingState({
    editableDrafts: { ...current.editableDrafts, [version]: draft },
    ...(current.activeDraftVersion === version
      ? { draftedFields: draft.fields }
      : {}),
  });
}
