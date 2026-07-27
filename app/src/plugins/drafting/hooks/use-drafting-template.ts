import { useState } from "react";
import { getConfig } from "@/config";
import { readConfigOptions } from "../config-options";
import type { DraftingSelectOption } from "../types";

/**
 * Owns the template selector state.
 *
 * Options come from the app init config (pluginConfig.drafting.templates).
 * TODO: Replace the no-op save with a backend template service once template
 * selection is persisted server side. The shape mirrors
 * useDraftingGenerationSettings so the panel wiring is identical.
 */
export function useDraftingTemplate() {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const templatesConfig = draftingConfig.templates as
    | { enabled?: boolean; options?: unknown }
    | undefined;
  const enabled = templatesConfig?.enabled ?? false;
  // Host options use { id, label, description }; map to the card shape.
  const options = readConfigOptions<DraftingSelectOption>(
    templatesConfig?.options,
  ).map((option) => ({
    value: option.id,
    label: option.label,
    description: option.description,
  }));
  const [confirmedId, setConfirmedId] = useState(options[0]?.value ?? "");
  const [value, setValue] = useState(confirmedId);

  const hasChanges = value !== confirmedId;
  const selectedLabel =
    options.find((option) => option.value === confirmedId)?.label ?? null;

  function updateValue(nextId: string) {
    setValue(nextId);
  }

  function discardChanges() {
    // Restore the controlled selection to the confirmed template.
    setValue(confirmedId);
  }

  async function submitValues() {
    if (!value || !hasChanges) {
      return;
    }
    // TODO: Persist the selected template server side.
    setConfirmedId(value);
  }

  return {
    enabled,
    options,
    value,
    updateValue,
    discardChanges,
    submitValues,
    hasChanges,
    selectedLabel,
  };
}
