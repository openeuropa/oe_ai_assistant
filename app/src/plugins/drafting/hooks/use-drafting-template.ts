import { useState } from "react";
import type { RadioCardOption } from "@/components/ui/radio-card-group";
import { getConfig } from "@/config";

/**
 * Reads the host-provided template options from the app init config.
 *
 * Host options use { id, label, description }; they are mapped to the card
 * shape ({ value, ... }) the panel expects.
 */
function getTemplateOptions(value: unknown): RadioCardOption[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .filter(
      (option): option is { id: string; label: string; description: string } =>
        Boolean(option) &&
        typeof option === "object" &&
        typeof (option as Record<string, unknown>).id === "string" &&
        typeof (option as Record<string, unknown>).label === "string" &&
        typeof (option as Record<string, unknown>).description === "string",
    )
    .map((option) => ({
      value: option.id,
      label: option.label,
      description: option.description,
    }));
}

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
  const options = getTemplateOptions(templatesConfig?.options);
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
