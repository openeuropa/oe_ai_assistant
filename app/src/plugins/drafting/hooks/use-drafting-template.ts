import { useState } from "react";
import type { RadioCardOption } from "@/components/ui/radio-card-group";

// Mock template options until a backend template service exists.
const TEMPLATE_OPTIONS: RadioCardOption[] = [
  {
    value: "news-article",
    label: "News article",
    description:
      "Structured article with headline, summary, body, and related links.",
  },
  {
    value: "press-release",
    label: "Press release",
    description:
      "Announcement-focused structure with key messages and media angle.",
  },
  {
    value: "policy-brief",
    label: "Policy brief",
    description:
      "Short explanatory format focused on context, impact, and next steps.",
  },
];

/**
 * Owns the template selector state.
 *
 * TODO: Replace the mock options and no-op save with a backend template
 * service once template selection is persisted server side. The shape
 * mirrors useDraftingGenerationSettings so the panel wiring is identical.
 */
export function useDraftingTemplate() {
  const options = TEMPLATE_OPTIONS;
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
    options,
    value,
    updateValue,
    discardChanges,
    submitValues,
    hasChanges,
    selectedLabel,
  };
}
