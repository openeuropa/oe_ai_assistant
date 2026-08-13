import { useEffect } from "react";
import { getConfig } from "@/config";
import { setDraftingTone } from "../api/drafting-api";
import { readConfigOptions } from "../config-options";
import { setDraftingSelection } from "../store";
import type { DraftingSelectOption } from "../types";
import { useCardSelection } from "./use-card-selection";

/**
 * Owns the tone selector state.
 *
 * Options come from the app init config (pluginConfig.drafting.tone). The
 * confirmed tone is rehydrated from the server-provided init config and
 * persisted via the set-tone endpoint; the selection state machine and store
 * persistence are shared via useCardSelection.
 */
export function useDraftingTone() {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const toneConfig = draftingConfig.tone as
    | { enabled?: boolean; options?: unknown; selected?: string }
    | undefined;
  const enabled = toneConfig?.enabled ?? false;
  // Host options use { id, label, description }; map to the card shape.
  const options = readConfigOptions<DraftingSelectOption>(
    toneConfig?.options,
  ).map((option) => ({
    value: option.id,
    label: option.label,
    description: option.description,
  }));
  const selectedToneId = toneConfig?.selected ?? "";

  useEffect(() => {
    // Rehydrate the confirmed tone from the server-provided init config so the
    // selector reflects the tone currently saved on the session.
    if (selectedToneId) {
      setDraftingSelection("tone", selectedToneId);
    }
  }, [selectedToneId]);

  const selection = useCardSelection({
    panelId: "tone",
    options,
    onSave: async (toneId) => {
      await setDraftingTone({ toneId });
    },
  });

  return { enabled, ...selection };
}
