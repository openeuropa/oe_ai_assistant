/**
 * DraftCard component.
 *
 * Displays one draft of the session as a card in the chat thread. The card is
 * shown from the moment the drafting run starts, so it reports the progress of
 * every field group while the run works and keeps those rows afterwards. The
 * title is a button whose stretched overlay makes the whole card surface call
 * onOpen, which repopulates the artifact panel. The popovers sit above the
 * overlay, so they open without triggering onOpen.
 */

import {
  CalendarClock,
  CircleCheck,
  CircleDashed,
  CircleX,
  FileText,
  LayoutTemplate,
  Loader2,
  Megaphone,
  PenLine,
} from "lucide-react";
import { type ReactNode, useState } from "react";
import type {
  DraftContext,
  DraftDocumentSnapshot,
  DraftGroupProgress,
  DraftRunStatus,
} from "../draft-result";
import { formatDraftDateShort } from "../format-draft-date";
import { DocumentDetailsDialog } from "./document-details-dialog";

/** Props for DraftCard. */
export interface DraftCardProps {
  /** Numeric draft version, or null for a legacy draft without version info. */
  version: number | null;
  /**
   * The editorial context captured when the draft was generated. Null for
   * legacy drafts that pre-date context tracking.
   */
  context: DraftContext | null;
  /**
   * The field groups of the content type, in the order it defines them. Empty
   * for a legacy draft that recorded no group progress.
   */
  groups: DraftGroupProgress[];
  /** Where the run stands. */
  status: DraftRunStatus;
  /**
   * The error of a run that failed outside any group, opened from the header
   * cross. A run that failed while drafting a group records its error on that
   * group instead, so this is absent.
   */
  error?: string;
  /** Whether this version has been saved as a revision. */
  isSaved: boolean;
  /** When the draft was generated; null when unknown. */
  createdAt: Date | null;
  /** Called when the user clicks the card body to view this draft. */
  onOpen: () => void;
}

/**
 * Returns the card title based on the draft version.
 *
 * "Draft N" when a numeric version is available, plain "Draft" otherwise.
 */
function draftTitle(version: number | null): string {
  return version !== null ? `Draft ${version}` : "Draft";
}

/**
 * A trigger that opens a panel on top of the card.
 *
 * The card is one click target, and a nested button inside it would be out of
 * reach under the stretched overlay. So this trigger sits above the overlay,
 * and its panel sits above the backdrop that closes it.
 */
function Popover({
  ariaLabel,
  className,
  trigger,
  children,
}: {
  ariaLabel: string;
  /** Classes for the trigger button, which carries the look of the item. */
  className: string;
  trigger: ReactNode;
  /** The panel content. Call close to dismiss the panel from inside it. */
  children: (close: () => void) => ReactNode;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const close = () => setIsOpen(false);

  return (
    <span className="relative z-10 flex min-w-0">
      <button
        type="button"
        className={`cursor-pointer ${className}`}
        aria-label={ariaLabel}
        aria-expanded={isOpen}
        onClick={() => setIsOpen(!isOpen)}
        onKeyDown={(event) => {
          // The artifact pane collapses on Escape, and it lets a press that is
          // already handled pass. This press closes the panel only.
          if (event.key === "Escape" && isOpen) {
            event.preventDefault();
            close();
          }
        }}
      >
        {trigger}
      </button>

      {isOpen && (
        <>
          {/* A click outside the panel closes it. The backdrop takes that
              click, so the card below it keeps its own state. */}
          <button
            type="button"
            tabIndex={-1}
            aria-hidden="true"
            className="fixed inset-0 z-20 cursor-default"
            onClick={close}
          />
          <span className="absolute top-5 left-0 z-30 w-72 rounded-md border border-gray-200 bg-white p-2 text-xs leading-relaxed text-gray-600 shadow-lg">
            {children(close)}
          </span>
        </>
      )}
    </span>
  );
}

/**
 * The header icon, which says where the draft stands.
 *
 * A line that only one state carries moves everything under it when that
 * state ends, and the whole card jumps as the last group lands. So the header
 * icon carries the state on its own: it spins while the run works, and the run
 * settles into a cross, a dashed circle for a draft the editor has not saved,
 * or a tick for one that is saved. A failed run that recorded a message opens
 * it from the cross.
 */
function StatusIcon({
  status,
  error,
  isSaved,
}: {
  status: DraftRunStatus;
  error?: string;
  isSaved: boolean;
}) {
  if (status === "running") {
    return (
      <Loader2
        size={16}
        className="animate-spin text-blue-500"
        aria-label="Drafting"
      />
    );
  }
  if (status === "failed") {
    return error ? (
      <Popover
        ariaLabel="Show the error"
        className="flex items-center"
        trigger={<CircleX size={16} className="shrink-0 text-red-500" />}
      >
        {() => error}
      </Popover>
    ) : (
      <CircleX size={16} className="text-red-500" aria-label="Failed" />
    );
  }
  if (isSaved) {
    return (
      <CircleCheck size={16} className="text-green-600" aria-label="Saved" />
    );
  }
  return (
    <CircleDashed size={16} className="text-gray-400" aria-label="Not saved" />
  );
}

/**
 * One item of the meta line: an icon and its value, with no written label.
 *
 * The line holds what the editor set up before the run, and a label in front
 * of every value doubles its length for nothing: a date, a tone and a template
 * tell the editor what they are. The icon names the item, and the tooltip
 * spells it out.
 */
function MetaItem({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof Megaphone;
  label: string;
  children: ReactNode;
}) {
  return (
    <span className="inline-flex min-w-0 items-center gap-1" title={label}>
      <Icon size={12} className="shrink-0 text-gray-400" aria-hidden="true" />
      <span className="truncate">{children}</span>
    </span>
  );
}

/**
 * The icon and the text colour that each group status carries.
 */
const GROUP_STATUS_STYLE = {
  pending: {
    icon: CircleDashed,
    iconClass: "text-gray-300",
    labelClass: "text-gray-400",
  },
  drafting: {
    icon: Loader2,
    iconClass: "animate-spin text-blue-500",
    labelClass: "text-gray-700",
  },
  drafted: {
    icon: CircleCheck,
    iconClass: "text-green-600",
    labelClass: "text-gray-600",
  },
  failed: {
    icon: CircleX,
    iconClass: "text-red-500",
    labelClass: "text-red-600",
  },
} as const;

/**
 * One field group of the draft, with the progress the run reported for it.
 */
function GroupRow({ group }: { group: DraftGroupProgress }) {
  const style = GROUP_STATUS_STYLE[group.status];
  const Icon = style.icon;

  return (
    <div className="flex items-start gap-1.5 text-xs">
      {group.status === "failed" && group.error ? (
        <Popover
          ariaLabel="Show the error"
          className="flex items-center"
          trigger={<CircleX size={14} className="shrink-0 text-red-500" />}
        >
          {() => group.error}
        </Popover>
      ) : (
        <Icon
          size={14}
          className={`mt-px shrink-0 ${style.iconClass}`}
          aria-hidden="true"
        />
      )}
      <span className={style.labelClass}>{group.label}</span>
    </div>
  );
}

/**
 * Card that summarises one draft of the session.
 *
 * Styled to match ToolCallCard in tool-uis.tsx for visual consistency in the
 * chat thread. The header icon carries the progress of the run, so no row of
 * the card comes and goes and nothing under it moves.
 */
export function DraftCard({
  version,
  context,
  groups,
  status,
  error,
  isSaved,
  createdAt,
  onOpen,
}: DraftCardProps) {
  // The currently selected document for the detail dialog; null means closed.
  const [selectedDocument, setSelectedDocument] =
    useState<DraftDocumentSnapshot | null>(null);

  const documents = context?.documents ?? [];
  const tone = context?.tone ?? null;
  const template = context?.template ?? null;

  // A draft reaches the artifact pane only after the run that writes it ends.
  const isOpenable = status === "drafted";

  const hasMeta = tone !== null || template !== null || documents.length > 0;

  /**
   * The context documents, counted on the meta line and listed in a panel.
   *
   * A document title is often a file name, which is long and joins its words
   * with underscores. Several of them on the meta line leave no room for what
   * else the editor set up, so the line carries the count and the panel
   * carries the names.
   */
  const documentsItem = (
    <Popover
      ariaLabel="Show the context documents"
      className="inline-flex min-w-0 items-center gap-1 underline-offset-2 hover:underline"
      trigger={
        <>
          <FileText
            size={12}
            className="shrink-0 text-gray-400"
            aria-hidden="true"
          />
          <span className="truncate">
            {documents.length} context{" "}
            {documents.length === 1 ? "document" : "documents"}
          </span>
        </>
      }
    >
      {(close) => (
        <span className="flex flex-col gap-1">
          {documents.map((doc) => (
            <button
              key={doc.id}
              type="button"
              title={doc.title}
              className="inline-flex min-w-0 cursor-pointer items-center gap-1 text-left text-blue-600 underline-offset-2 hover:underline"
              onClick={() => {
                setSelectedDocument(doc);
                close();
              }}
            >
              <FileText
                size={12}
                className="shrink-0 text-gray-400"
                aria-hidden="true"
              />
              <span className="truncate">{doc.title}</span>
            </button>
          ))}
        </span>
      )}
    </Popover>
  );

  return (
    <>
      {/* Relative card wrapper: the title button's stretched overlay makes
          the whole surface clickable without nesting interactive elements. */}
      <div
        className={`relative my-4 flex w-full flex-col items-start gap-0 rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-left ${
          isOpenable
            ? "transition-colors hover:border-gray-300 hover:bg-gray-50"
            : ""
        }`}
      >
        {/* Header row: status icon, PenLine icon, and title. */}
        <div className="flex w-full items-start gap-3">
          <div className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
            <StatusIcon status={status} error={error} isSaved={isSaved} />
          </div>

          <div className="min-w-0 flex-1">
            {/* Title line: the draft name, and the time beside it. The name
                is a button only while the draft can be opened. Its after
                overlay stretches the click target over the card; the popovers
                sit above it with z-10. */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
              {isOpenable ? (
                <button
                  type="button"
                  className="flex cursor-pointer items-center gap-1.5 after:absolute after:inset-0 after:content-['']"
                  onClick={onOpen}
                >
                  <PenLine size={14} className="shrink-0 text-gray-400" />
                  <span className="text-sm font-medium text-gray-700">
                    {draftTitle(version)}
                  </span>
                </button>
              ) : (
                <div className="flex items-center gap-1.5">
                  <PenLine size={14} className="shrink-0 text-gray-400" />
                  <span className="text-sm font-medium text-gray-700">
                    {draftTitle(version)}
                  </span>
                </div>
              )}

              {/* The time the run stored the draft. Nothing follows it on the
                  line, so the line keeps its shape when the run ends and the
                  time arrives. */}
              {status !== "running" && createdAt && (
                <span
                  className="ml-auto inline-flex min-w-0 items-center gap-1 text-xs text-gray-400"
                  title="Created"
                >
                  <CalendarClock
                    size={12}
                    className="shrink-0"
                    aria-hidden="true"
                  />
                  <span className="truncate">
                    {formatDraftDateShort(createdAt)}
                  </span>
                </span>
              )}
            </div>

            {/* Meta line: what the editor set up, one icon and value apiece. */}
            {hasMeta && (
              <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                {tone && (
                  <MetaItem icon={Megaphone} label="Tone">
                    {tone.label}
                  </MetaItem>
                )}
                {template && (
                  <MetaItem icon={LayoutTemplate} label="Template">
                    {template.label}
                  </MetaItem>
                )}
                {documents.length > 0 && documentsItem}
              </div>
            )}

            {/* One row per field group of the content type. */}
            {groups.length > 0 && (
              <div className="mt-2 flex flex-col gap-1">
                {groups.map((group) => (
                  <GroupRow key={group.id} group={group} />
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Document details dialog: controlled by selectedDocument state. */}
      <DocumentDetailsDialog
        document={selectedDocument}
        onClose={() => setSelectedDocument(null)}
      />
    </>
  );
}
