import { useEffect } from "react";
import { getConfig } from "@/config";
import { setDraftingTemplate } from "../api/drafting-api";
import { readConfigOptions } from "../config-options";
import { setDraftingSelection } from "../store";
import type { DraftingSelectOption } from "../types";
import { useCardSelection } from "./use-card-selection";

/**
 * Owns the template selector state.
 *
 * Options come from the app init config (pluginConfig.drafting.templates). The
 * confirmed template is rehydrated from the server-provided init config and
 * persisted via the set-template endpoint; the selection state machine and
 * store persistence are shared via useCardSelection.
 */
export function useDraftingTemplate() {
  const draftingConfig = getConfig().pluginConfig.drafting ?? {};
  const templatesConfig = draftingConfig.templates as
    | { enabled?: boolean; options?: unknown; selected?: string }
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
  const selectedTemplateId = templatesConfig?.selected ?? "";

  useEffect(() => {
    // Rehydrate the confirmed template from the server-provided init config so
    // the selector reflects the template currently saved on the session.
    if (selectedTemplateId) {
      setDraftingSelection("template", selectedTemplateId);
    }
  }, [selectedTemplateId]);

  const selection = useCardSelection({
    panelId: "template",
    options,
    onSave: async (templateId) => {
      await setDraftingTemplate({ template: templateId });
    },
  });

  return { enabled, ...selection };
}
