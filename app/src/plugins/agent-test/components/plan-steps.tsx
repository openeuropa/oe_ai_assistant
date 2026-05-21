/**
 * Displays the orchestration plan with step progress indicators.
 *
 * Shows a spinner for in-progress steps and a green check for
 * completed steps.
 */
import { CheckCircle2, CircleDashed, Loader2, XCircle } from "lucide-react";
import type { PlanStep } from "../types";

interface PlanStepsProps {
  steps: PlanStep[];
}

/** Maps step IDs to human-readable labels. */
const stepLabels: Record<string, string> = {
  main_fields: "Generating title and summary",
  item_hero: "Generating hero section",
  item_text_block: "Generating text block",
};

export function PlanSteps({ steps }: PlanStepsProps) {
  if (steps.length === 0) return null;

  return (
    <div className="my-3 space-y-2 rounded-md border p-3">
      <p className="text-sm font-medium text-muted-foreground">
        Drafting content...
      </p>
      {steps.map((step) => (
        <div key={step.stepId} className="flex items-center gap-2 text-sm">
          <StepIcon status={step.status} />
          <span
            className={
              step.status === "done" ? "text-muted-foreground" : undefined
            }
          >
            {stepLabels[step.stepId] ?? step.stepId}
          </span>
        </div>
      ))}
    </div>
  );
}

function StepIcon({ status }: { status: PlanStep["status"] }) {
  switch (status) {
    case "pending":
      return <CircleDashed className="h-4 w-4 text-muted-foreground" />;
    case "in_progress":
      return <Loader2 className="h-4 w-4 animate-spin text-blue-500" />;
    case "done":
      return <CheckCircle2 className="h-4 w-4 text-green-500" />;
    case "error":
      return <XCircle className="h-4 w-4 text-red-500" />;
  }
}
