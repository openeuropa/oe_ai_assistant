/**
 * SubagentCard component.
 *
 * Shows the work of a subagent in the chat thread. While the run streams, the
 * card lists the tool calls the subagent makes and previews the text it
 * writes. Once the call settles, the card reads the result alone, which is
 * also all a reloaded thread has: the report with its citations, the question
 * the subagent asks the editor, or the reason it failed.
 */

import {
  BadgeCheck,
  Check,
  CircleHelp,
  Loader2,
  type LucideIcon,
  Search,
  TriangleAlert,
  X,
} from "lucide-react";
import { type ReactNode, useMemo } from "react";
import { Streamdown } from "streamdown";
import { useDraftingSlice } from "../store";
import {
  type ReportToken,
  type SubagentCallResult,
  type SubagentLive,
  splitReport,
} from "../subagent-result";
import {
  humanizeToolName,
  type SubagentRun,
  type SubagentStep,
  summarizeStepInput,
} from "../subagent-runs";
import { toolLabel } from "../tool-labels";

/** Props for SubagentCard. */
export interface SubagentCardProps {
  /** The name of the tool that handed the work to the subagent. */
  toolName: string;
  /** The live run of the call, if this tab streamed it. */
  run?: SubagentRun | undefined;
  /** The settled result of the call, if it settled. */
  result: SubagentCallResult | null;
  /** Whether the call itself is still waiting for its result. */
  isRunning: boolean;
}

/** What the badge in the header says and how it looks. */
interface Badge {
  label: string;
  className: string;
}

const BADGE_BASE =
  "inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-medium";

/** Returns the header badge of a card. */
function badgeFor(status: string): Badge {
  switch (status) {
    case "completed":
      return {
        label: "Completed",
        className: `${BADGE_BASE} border-green-200 bg-green-50 text-green-700`,
      };
    case "needs_input":
      return {
        label: "Needs input",
        className: `${BADGE_BASE} border-amber-200 bg-amber-50 text-amber-700`,
      };
    case "failed":
    case "stopped":
      return {
        label: "Failed",
        className: `${BADGE_BASE} border-red-200 bg-red-50 text-red-600`,
      };
    case "queued":
    case "pending":
      return {
        label: "In the background",
        className: `${BADGE_BASE} border-gray-200 bg-gray-50 text-gray-500`,
      };
    default:
      return {
        label: "Running",
        className: `${BADGE_BASE} border-blue-200 bg-blue-50 text-blue-600`,
      };
  }
}

/** Names the subagent the way the header shows it: researcher, Researcher. */
function subagentTitle(name: string, fallback: string): string {
  return name === "" ? fallback : name.charAt(0).toUpperCase() + name.slice(1);
}

/** The icon of a step, by where the call stands. */
function StepIcon({ status }: { status: SubagentStep["status"] }) {
  if (status === "running") {
    return <Loader2 size={14} className="animate-spin text-blue-500" />;
  }
  if (status === "failed") {
    return <X size={14} className="text-red-500" />;
  }
  return <Check size={14} className="text-green-500" />;
}

/** The tool calls the subagent made, one row each. */
function StepList({ steps }: { steps: SubagentStep[] }) {
  if (steps.length === 0) return null;
  return (
    <ul className="mt-2 space-y-1" data-testid="subagent-steps">
      {steps.map((step) => {
        const summary = summarizeStepInput(step.input);
        return (
          <li
            key={step.id}
            className="flex items-center gap-2 text-xs"
            data-testid="subagent-step"
            data-status={step.status}
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <StepIcon status={step.status} />
            </span>
            <span className="font-medium text-gray-700">
              {humanizeToolName(step.tool)}
            </span>
            {summary !== "" && (
              <span className="min-w-0 truncate text-gray-400">{summary}</span>
            )}
          </li>
        );
      })}
    </ul>
  );
}

/** The small chip that stands for one cited page. */
function CitationChip({
  token,
}: {
  token: Extract<ReportToken, { type: "citation" }>;
}) {
  const Icon = token.verified ? BadgeCheck : CircleHelp;
  const look = token.verified
    ? "border-green-200 bg-green-50 text-green-700"
    : "border-amber-200 bg-amber-50 text-amber-700";
  return (
    <span
      className={`mx-0.5 inline-flex items-center gap-1 rounded-full border px-1.5 py-px align-baseline text-xs ${look}`}
      title={token.verified ? "Verified in the document" : "Not verified"}
      data-testid="citation-chip"
      data-verified={token.verified ? "true" : "false"}
    >
      <Icon size={11} className="shrink-0" aria-hidden="true" />
      {token.label}
    </span>
  );
}

/** Escapes the characters that would end a markdown link label. */
function escapeLabel(label: string): string {
  return label.replace(/[\\[\]]/g, "\\$&");
}

/** The report as markdown, with each marker drawn as a citation chip. */
function Report({ tokens }: { tokens: ReportToken[] }) {
  // The markers become links to #cite-<n>, and the link renderer draws the
  // chip of token n. That keeps the chips inline in the markdown flow.
  const markdown = tokens
    .map((token, index) =>
      token.type === "text"
        ? token.text
        : `[${escapeLabel(token.label)}](#cite-${index})`,
    )
    .join("");

  return (
    <div
      className="chat-markdown prose prose-sm mt-3 max-w-none"
      data-testid="subagent-report"
    >
      <Streamdown
        components={{
          a: ({ href, children }) => {
            const token = href?.startsWith("#cite-")
              ? tokens[Number(href.slice("#cite-".length))]
              : undefined;
            if (token?.type === "citation") {
              return <CitationChip token={token} />;
            }
            return <span>{children}</span>;
          },
        }}
      >
        {markdown}
      </Streamdown>
    </div>
  );
}

/** The sources the result lists, one row each. */
function CitationList({
  tokens,
}: {
  tokens: Extract<ReportToken, { type: "citation" }>[];
}) {
  if (tokens.length === 0) return null;
  return (
    <div className="mt-3 border-t border-gray-100 pt-2">
      <p className="text-xs font-medium text-gray-500">Sources</p>
      <ul className="mt-1 space-y-0.5" data-testid="citation-list">
        {tokens.map((token) => (
          <li
            key={`${token.documentId}-${token.page}`}
            className="flex items-center gap-1.5 text-xs text-gray-600"
            data-verified={token.verified ? "true" : "false"}
          >
            {token.verified ? (
              <BadgeCheck size={12} className="text-green-600" />
            ) : (
              <CircleHelp size={12} className="text-amber-600" />
            )}
            {token.label}
            <span className="text-gray-400">
              {token.verified ? "verified" : "not verified"}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** What a pending background call says, read off the ledger after a reload. */
function pendingMessage(live: SubagentLive | null): string {
  if (live === null) return "Researching in the background…";
  if (live.status === "failed" || live.status === "stopped") return "Failed";
  if (live.status === "completed" || live.status === "needs_input") {
    return live.deliveryState === "delivered" ||
      live.deliveryState === "consumed"
      ? "Done, results delivered"
      : "Done, waiting to be delivered";
  }
  return "Researching in the background…";
}

/** A box that sets a message apart from the rest of the card. */
function Callout({
  icon: Icon,
  className,
  children,
}: {
  icon: LucideIcon;
  className: string;
  children: ReactNode;
}) {
  return (
    <div
      className={`mt-3 flex items-start gap-2 rounded-md border px-3 py-2 text-sm ${className}`}
    >
      <Icon size={16} className="mt-0.5 shrink-0" />
      <div className="min-w-0">{children}</div>
    </div>
  );
}

/** The body the settled result gives the card. */
function ResultBody({
  result,
  titles,
}: {
  result: SubagentCallResult;
  titles: Record<string, string>;
}) {
  const tokens = useMemo(
    () => splitReport(result.report, result.citations, titles),
    [result.report, result.citations, titles],
  );

  if (result.status === "pending" || result.status === "running") {
    return (
      <p className="mt-2 text-sm text-gray-500" data-testid="subagent-pending">
        {pendingMessage(result.live)}
      </p>
    );
  }

  if (result.status === "needs_input") {
    return (
      <Callout icon={CircleHelp} className="border-amber-200 bg-amber-50">
        <p className="font-medium text-amber-800">The researcher asks:</p>
        <p className="mt-0.5 text-amber-900">{result.question}</p>
      </Callout>
    );
  }

  if (result.status === "failed" || result.status === "stopped") {
    return (
      <Callout icon={TriangleAlert} className="border-red-200 bg-red-50">
        <p className="text-red-700">
          {result.error || "The research did not finish."}
        </p>
      </Callout>
    );
  }

  // Each cited page appears once in the list, in the order the report cites it.
  const cited = tokens.filter(
    (token): token is Extract<ReportToken, { type: "citation" }> =>
      token.type === "citation",
  );
  const unique = cited.filter(
    (token, index) =>
      cited.findIndex(
        (other) =>
          other.documentId === token.documentId && other.page === token.page,
      ) === index,
  );
  return (
    <>
      <Report tokens={tokens} />
      {result.note !== "" && (
        <p className="mt-2 text-xs text-gray-400">{result.note}</p>
      )}
      <CitationList tokens={unique} />
    </>
  );
}

/**
 * The card of a call that hands work to a subagent.
 *
 * Reads the live run while the call works, and the result once it settles.
 */
export function SubagentCard({
  toolName,
  run,
  result,
  isRunning,
}: SubagentCardProps) {
  const { documentTitles } = useDraftingSlice();
  // A call that ended with no result and no run left nothing to follow, as
  // when a reloaded thread finds the call open.
  const abandoned = result === null && !run && !isRunning;
  const status = abandoned
    ? "failed"
    : (result?.status ?? run?.status ?? "running");
  // A pending background call settles at once, so the run says whether the
  // worker has it yet. A settled foreground call has the final word itself.
  const badge = badgeFor(
    result?.status === "pending" && run ? run.status : status,
  );
  const title = subagentTitle(
    result?.subagent || run?.subagent || "",
    toolLabel(toolName),
  );
  const settled =
    result !== null &&
    result.status !== "pending" &&
    result.status !== "running";

  return (
    <div
      className="my-4 w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5"
      data-testid="subagent-card"
      data-status={status}
    >
      <div className="flex items-center gap-2">
        <Search size={14} className="shrink-0 text-gray-400" />
        <span className="min-w-0 flex-1 truncate text-sm font-medium text-gray-700">
          {title}
        </span>
        <span className={badge.className} data-testid="subagent-badge">
          {badge.label}
        </span>
      </div>

      {run && <StepList steps={run.steps} />}

      {!settled && run && run.text !== "" && (
        <details className="mt-2" data-testid="subagent-text">
          <summary className="cursor-pointer text-xs text-gray-500">
            Notes so far
          </summary>
          <p className="mt-1 max-h-40 overflow-auto whitespace-pre-wrap text-xs text-gray-500">
            {run.text}
          </p>
        </details>
      )}

      {result ? (
        <ResultBody result={result} titles={documentTitles} />
      ) : (
        <p className="mt-2 text-sm text-gray-500">
          {abandoned ? "The research did not finish." : "Researching…"}
        </p>
      )}
    </div>
  );
}
