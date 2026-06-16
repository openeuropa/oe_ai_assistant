import { MessageSquare, Send, X } from "lucide-react";
import { type ReactNode, useEffect, useState } from "react";

const relevanceOptions = ["Yes, completely", "Partially", "No"] as const;
const yesNoOptions = ["Yes", "No"] as const;

type RelevanceOption = (typeof relevanceOptions)[number];
type YesNoOption = (typeof yesNoOptions)[number];

export type FeedbackFormValues = {
  messageId: string;
  relevance: RelevanceOption;
  matchesSources: YesNoOption;
  sourcesSupport: YesNoOption;
  feedback: string;
  missingSources: string;
};

type FeedbackDialogProps = {
  isSubmitting: boolean;
  messageId: string | undefined;
  onClose: () => void;
  onSubmit: (values: FeedbackFormValues) => void;
  submitError: string | null;
};

export function FeedbackDialog({
  isSubmitting,
  messageId,
  onClose,
  onSubmit,
  submitError,
}: FeedbackDialogProps) {
  const [feedback, setFeedback] = useState("");
  const [missingSources, setMissingSources] = useState("");
  const [relevance, setRelevance] =
    useState<RelevanceOption>("Yes, completely");
  const [matchesSources, setMatchesSources] = useState<YesNoOption>("Yes");
  const [sourcesSupport, setSourcesSupport] = useState<YesNoOption>("Yes");
  const trimmedFeedback = feedback.trim();
  const fieldId = messageId ?? "current";

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onClose();
      }
    }

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  function submitDialog() {
    if (!messageId || !trimmedFeedback) return;
    onSubmit({
      messageId,
      relevance,
      matchesSources,
      sourcesSupport,
      feedback: trimmedFeedback,
      missingSources: missingSources.trim(),
    });
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 px-4 py-6">
      <div
        aria-labelledby="assistant-feedback-title"
        aria-modal="true"
        className="flex max-h-full w-full max-w-lg flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-xl"
        role="dialog"
      >
        <div className="flex items-center justify-between gap-4 border-b border-gray-200 bg-gray-50 px-5 py-4">
          <div className="flex min-w-0 items-center gap-3">
            <MessageSquare
              size={22}
              className="shrink-0 text-gray-900"
              strokeWidth={1.7}
            />
            <h2
              className="text-xl font-semibold text-gray-900"
              id="assistant-feedback-title"
            >
              Send your feedback
            </h2>
          </div>
          <button
            type="button"
            aria-label="Close feedback form"
            className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-gray-900 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            onClick={onClose}
          >
            <X size={20} strokeWidth={1.7} />
          </button>
        </div>

        <div className="overflow-y-auto px-5 py-4">
          <FeedbackQuestion
            label={
              <>
                Is the generated answer <strong>relevant and useful?</strong>
              </>
            }
          >
            <SegmentedControl
              label="Answer relevance"
              onChange={setRelevance}
              options={relevanceOptions}
              value={relevance}
            />
          </FeedbackQuestion>

          <FeedbackQuestion
            label={
              <>
                Does the answer <strong>match the provided sources?</strong>
              </>
            }
          >
            <SegmentedControl
              label="Source match"
              onChange={setMatchesSources}
              options={yesNoOptions}
              value={matchesSources}
            />
          </FeedbackQuestion>

          <FeedbackQuestion
            label={
              <>
                Do the <strong>source documents support the response?</strong>
              </>
            }
          >
            <SegmentedControl
              label="Source support"
              onChange={setSourcesSupport}
              options={yesNoOptions}
              value={sourcesSupport}
            />
          </FeedbackQuestion>

          <div className="mb-3">
            <label
              className="mb-2 block text-base text-gray-900"
              htmlFor={`assistant-feedback-${fieldId}`}
            >
              What could be <strong>improved in the answer?</strong>
            </label>
            <textarea
              className="min-h-28 w-full resize-y rounded-md border border-gray-300 px-3 py-2 text-sm leading-relaxed text-gray-900 placeholder:text-gray-500 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              id={`assistant-feedback-${fieldId}`}
              onChange={(event) => setFeedback(event.target.value)}
              placeholder="We'd love your feedback - how can we do better?"
              value={feedback}
            />
          </div>

          <div>
            <label
              className="mb-2 block text-base leading-snug text-gray-900"
              htmlFor={`assistant-missing-sources-${fieldId}`}
            >
              Are there any <strong>missing sources or information?</strong>
              <span className="block">If yes, please specify</span>
            </label>
            <textarea
              className="min-h-24 w-full resize-y rounded-md border border-gray-300 px-3 py-2 text-sm leading-relaxed text-gray-900 placeholder:text-gray-500 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
              id={`assistant-missing-sources-${fieldId}`}
              onChange={(event) => setMissingSources(event.target.value)}
              placeholder="We'd love your feedback - how can we do better?"
              value={missingSources}
            />
          </div>

          {submitError && (
            <p className="mt-4 text-sm text-red-600">{submitError}</p>
          )}
        </div>

        <div className="flex gap-3 border-t border-gray-200 px-5 py-4">
          <button
            type="button"
            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md bg-blue-700 px-3 text-sm font-medium text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            disabled={!trimmedFeedback || isSubmitting}
            onClick={submitDialog}
          >
            <Send size={16} strokeWidth={1.7} />
            {isSubmitting ? "Submitting..." : "Submit"}
          </button>
          <button
            type="button"
            className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-900 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
            onClick={onClose}
          >
            <X size={18} strokeWidth={1.7} />
            Close
          </button>
        </div>
      </div>
    </div>
  );
}

function FeedbackQuestion({
  children,
  label,
}: {
  children: ReactNode;
  label: ReactNode;
}) {
  return (
    <fieldset className="mb-3">
      <legend className="mb-2 text-base text-gray-900">{label}</legend>
      {children}
    </fieldset>
  );
}

function SegmentedControl<T extends string>({
  label,
  onChange,
  options,
  value,
}: {
  label: string;
  onChange: (value: T) => void;
  options: readonly T[];
  value: T;
}) {
  return (
    <div
      aria-label={label}
      className="inline-flex overflow-hidden rounded-md border border-gray-300"
      role="radiogroup"
    >
      {options.map((option, index) => {
        const isSelected = option === value;
        return (
          <label
            className={`inline-flex h-9 cursor-pointer items-center gap-2 border-gray-300 bg-white px-3 text-sm text-gray-900 hover:bg-gray-50 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ${
              index === 0 ? "" : "border-l"
            }`}
            key={option}
          >
            <input
              checked={isSelected}
              className="sr-only"
              name={label}
              onChange={() => onChange(option)}
              type="radio"
              value={option}
            />
            {option}
            {isSelected && (
              <span className="text-green-700" aria-hidden="true">
                ✓
              </span>
            )}
          </label>
        );
      })}
    </div>
  );
}
