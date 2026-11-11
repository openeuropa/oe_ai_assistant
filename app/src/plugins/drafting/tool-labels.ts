/**
 * The labels the host declares for the tools the model can call.
 *
 * The host sends them in the app init config, keyed by the machine name of
 * the tool.
 */

import { getConfig } from "@/config";

/**
 * Returns the label of one tool.
 *
 * @param toolName The name the model calls the tool by.
 * @returns The label the host declared, or the name when it declared none.
 */
export function toolLabel(toolName: string): string {
  const labels = getConfig().pluginConfig.drafting?.["toolLabels"];
  const label =
    typeof labels === "object" && labels !== null
      ? (labels as Record<string, unknown>)[toolName]
      : undefined;

  return typeof label === "string" && label !== "" ? label : toolName;
}
