import { useAuiState } from "@assistant-ui/react";
import { AlertCircle, MessageSquare, X } from "lucide-react";
import { useEffect, useState } from "react";
import { FeedbackDialog, type FeedbackFormValues } from "./feedback-dialog";

/** Always-visible response feedback prompt. */
export function AssistantFeedback() {
  const status = useAuiState((s) => s.message?.status);
  const messageId = useAuiState((s) => s.message?.id);
  const [isOpen, setIsOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    if (!isSubmitted) return;

    const timeoutId = window.setTimeout(() => {
      setIsSubmitted(false);
    }, 10_000);

    return () => window.clearTimeout(timeoutId);
  }, [isSubmitted]);

  if (status?.type !== "complete") {
    return null;
  }

  async function submitFeedback(values: FeedbackFormValues) {
    setIsSubmitting(true);
    setSubmitError(null);

    try {
      void values;
      await new Promise((resolve) => window.setTimeout(resolve, 300));

      setIsSubmitted(true);
      setIsOpen(false);
    } catch (error) {
      console.error("[drafting] Feedback submission failed:", error);
      setSubmitError("Feedback could not be sent. Please try again.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <>
      {isSubmitted && (
        <output
          className="absolute left-4 right-4 top-4 z-30 flex items-start gap-3 rounded-md border border-green-200 bg-green-50 px-4 py-3 pr-12 text-green-900 shadow-lg"
          data-testid="assistant-feedback-success"
        >
          <AlertCircle size={18} className="mt-0.5 shrink-0 text-green-700" />
          <div>
            <p className="text-base font-semibold">Thanks!</p>
            <p className="text-sm">Feedback is sent.</p>
          </div>
          <button
            type="button"
            aria-label="Dismiss feedback confirmation"
            className="absolute right-3 top-3 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-green-800 hover:bg-green-100 hover:text-green-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-700"
            onClick={() => setIsSubmitted(false)}
          >
            <X size={16} />
          </button>
        </output>
      )}

      <div
        className="mt-3 flex w-full items-center gap-3 rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-900"
        data-testid="assistant-feedback"
      >
        <MessageSquare size={18} className="shrink-0 text-gray-600" />
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-1">
          <span>We&apos;d love your</span>
          <button
            type="button"
            aria-label="Open feedback form for this response"
            className="cursor-pointer p-0 text-sm font-medium text-blue-700 underline underline-offset-2 transition-colors hover:text-blue-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            onClick={() => setIsOpen(true)}
          >
            feedback
          </button>
          <span>.</span>
        </div>
      </div>

      {isOpen && (
        <FeedbackDialog
          isSubmitting={isSubmitting}
          messageId={messageId}
          onClose={() => setIsOpen(false)}
          onSubmit={submitFeedback}
          submitError={submitError}
        />
      )}
    </>
  );
}
