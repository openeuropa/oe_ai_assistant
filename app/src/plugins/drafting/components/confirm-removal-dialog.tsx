import { Loader2 } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";

/** Props for the reusable destructive confirmation dialog. */
export interface ConfirmRemovalDialogProps {
  /** Whether the dialog is visible; the caller owns this state. */
  open: boolean;
  /** Dialog title, e.g. "Remove context document". */
  title: string;
  /** Confirmation message describing the consequences of the removal. */
  message: string;
  /** Label of the destructive confirm button, e.g. "Delete document". */
  confirmLabel: string;
  /**
   * Performs the removal. The dialog shows a spinner while the promise is
   * pending; a rejection keeps the dialog open and displays the error.
   * The caller closes the dialog on success by flipping `open`.
   */
  onConfirm: () => Promise<void>;
  /** Closes the dialog without removing. */
  onCancel: () => void;
}

/**
 * Destructive confirmation dialog with in-flight and error handling.
 *
 * Generic over the removed item: every visible text is a prop, so the
 * same dialog serves context documents now and publishable assets later.
 * While the confirm action runs, both buttons and the dialog close are
 * locked and the confirm button shows a spinner.
 */
export function ConfirmRemovalDialog({
  open,
  title,
  message,
  confirmLabel,
  onConfirm,
  onCancel,
}: ConfirmRemovalDialogProps) {
  // In-flight confirm action; locks the dialog and shows the spinner.
  const [isBusy, setIsBusy] = useState(false);
  // Failure of the last confirm attempt, shown inside the dialog.
  const [error, setError] = useState<string | null>(null);

  /** Runs the removal, keeping the dialog open with the error on failure. */
  async function handleConfirm() {
    setIsBusy(true);
    setError(null);
    try {
      await onConfirm();
    } catch (exception) {
      setError(
        exception instanceof Error
          ? exception.message
          : "The removal failed. Please try again.",
      );
    } finally {
      setIsBusy(false);
    }
  }

  /** Closes the dialog unless a removal is in flight. */
  function handleCancel() {
    if (isBusy) {
      return;
    }
    setError(null);
    onCancel();
  }

  return (
    <Dialog open={open} onClose={handleCancel} title={title}>
      <div className="space-y-4 text-sm text-gray-700">
        <p>{message}</p>

        {error && (
          <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
            {error}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <Button
            variant="outline"
            className="cursor-pointer"
            onClick={handleCancel}
            disabled={isBusy}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            className="cursor-pointer"
            onClick={() => void handleConfirm()}
            disabled={isBusy}
          >
            {isBusy && <Loader2 className="animate-spin" />}
            {confirmLabel}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
