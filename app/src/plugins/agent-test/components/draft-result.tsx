/**
 * Displays the consolidated draft JSON result.
 */
import type { DraftResult } from "../types";

interface DraftResultProps {
  draft: DraftResult;
}

export function DraftResultView({ draft }: DraftResultProps) {
  return (
    <div className="my-3 rounded-md border bg-muted/50 p-3">
      <p className="mb-2 text-sm font-medium text-muted-foreground">
        Draft result:
      </p>
      <pre className="overflow-x-auto text-xs">
        {JSON.stringify(draft, null, 2)}
      </pre>
    </div>
  );
}
