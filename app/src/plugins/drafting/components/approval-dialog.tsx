import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import type { DraftingApproval } from "../api/drafting-api";

/** Props for the dialog that asks the editor to confirm a waiting save. */
export interface ApprovalDialogProps {
  /** The call to confirm; null when nothing waits. */
  approval: DraftingApproval | null;
  /** Reports the editor's decision on a call. */
  onDecide: (callId: string, decision: "approve" | "reject") => void;
}

/**
 * Asks the editor to confirm a draft save the agent requested.
 *
 * Closing the dialog by any route other than Save rejects the call.
 */
export function ApprovalDialog({ approval, onDecide }: ApprovalDialogProps) {
  const version = approval?.inputs["version"];

  function decide(decision: "approve" | "reject") {
    if (approval) onDecide(approval.callId, decision);
  }

  return (
    <Dialog
      open={approval !== null}
      onClose={() => decide("reject")}
      title={`Save Draft ${version}?`}
      // Above the full-screen draft pane, which sits at z-[1300].
      layerClassName="z-[1400]"
    >
      <div className="space-y-4 text-sm text-gray-700">
        <p>{`Draft ${version} will be saved as a new unpublished revision of the content item.`}</p>
        <div className="flex justify-end gap-2">
          <Button
            variant="outline"
            className="cursor-pointer"
            onClick={() => decide("reject")}
          >
            Cancel
          </Button>
          <Button className="cursor-pointer" onClick={() => decide("approve")}>
            Save
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
