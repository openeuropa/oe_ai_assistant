import { getConfig } from "@/config";
import { readConfigOptions } from "../config-options";
import type { DraftingSelectOption } from "../types";
import { useCardSelection } from "./use-card-selection";

/**
 * Owns the template selector state.
 *
 * Options come from the app init config (pluginConfig.drafting.templates); the
 * selection state machine and store persistence are shared via useCardSelection.
 * TODO: Replace the no-op save with a backend template service once template
 * selection is persisted server side.
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

  const selection = useCardSelection({
    panelId: "template",
    options,
    // TODO: Persist the selected template server side.
    onSave: async () => {},
  });

  return { enabled, ...selection };
}
