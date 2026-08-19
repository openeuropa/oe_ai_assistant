/**
 * Controlled dialog wrapper over the radix-ui Dialog primitive.
 *
 * Provides overlay dimming, a centered white panel, a title slot, and a
 * close button in the top-right corner. Closes on overlay click and Escape
 * key press via Radix defaults.
 */

import { X } from "lucide-react";
import { Dialog as DialogPrimitive } from "radix-ui";
import type * as React from "react";

import { cn } from "@/lib/utils";

/** Props for the controlled Dialog component. */
export interface DialogProps {
  /** Whether the dialog is currently visible. */
  open: boolean;
  /** Called when the user requests the dialog to close. */
  onClose: () => void;
  /** Text rendered as the accessible dialog title. */
  title: string;
  /** Content rendered inside the dialog panel. */
  children: React.ReactNode;
  /** Optional extra class names for the panel element. */
  className?: string;
  /**
   * Hides the header row and body padding for content that brings its
   * own chrome (title, description, close action). The title is still
   * rendered visually hidden for accessibility.
   */
  hideHeader?: boolean;
}

/**
 * Minimal controlled dialog built on Radix Dialog primitives.
 *
 * The caller drives open/close state via the `open` and `onClose` props.
 * There is no trigger element; the dialog is opened programmatically.
 */
export function Dialog({
  open,
  onClose,
  title,
  children,
  className,
  hideHeader = false,
}: DialogProps) {
  return (
    <DialogPrimitive.Root
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) onClose();
      }}
    >
      <DialogPrimitive.Portal>
        {/* Dimmed overlay behind the panel. The data-ai-app attribute
            re-applies the scoped CSS reset inside the portal, which
            renders outside the app mount into the host page body. */}
        <DialogPrimitive.Overlay
          data-ai-app=""
          className="fixed inset-0 z-50 bg-black/40 data-[state=open]:animate-in data-[state=open]:fade-in-0"
        />

        {/* Centered content panel; closes without an exit animation. The
            explicit h-auto overrides the 100vh container baseline that
            the data-ai-app reset scope would otherwise apply. */}
        <DialogPrimitive.Content
          data-ai-app=""
          className={cn(
            "fixed left-1/2 top-1/2 z-50 h-auto w-full max-w-lg -translate-x-1/2 -translate-y-1/2",
            "overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg",
            "data-[state=open]:animate-in",
            "data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95",
            className,
          )}
        >
          {hideHeader ? (
            // The content brings its own chrome; keep the title for
            // screen readers only. Uses the sr-only utility rather than
            // Radix VisuallyHidden: the scoped reset reverts inline
            // styles inside the portal, which would make the visually
            // hidden title show up.
            <DialogPrimitive.Title className="sr-only">
              {title}
            </DialogPrimitive.Title>
          ) : (
            // Header row: title on the left, close button on the right.
            <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
              <DialogPrimitive.Title className="text-sm font-semibold text-gray-800">
                {title}
              </DialogPrimitive.Title>

              {/* Close button: Radix Close triggers onOpenChange(false). */}
              <DialogPrimitive.Close
                className="cursor-pointer rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300"
                aria-label="Close dialog"
              >
                <X size={16} />
              </DialogPrimitive.Close>
            </div>
          )}

          {/* Panel body; headerless content manages its own padding. */}
          <div className={hideHeader ? undefined : "px-5 py-4"}>{children}</div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}
