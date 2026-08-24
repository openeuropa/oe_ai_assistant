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
  /**
   * Screen-reader-only text describing the dialog. When omitted, the
   * aria-describedby wiring is explicitly disabled per the Radix docs.
   */
  description?: string;
  /** Content rendered inside the dialog panel. */
  children: React.ReactNode;
  /** Optional extra class names for the panel element. */
  className?: string;
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
  description,
  children,
  className,
}: DialogProps) {
  return (
    <DialogPrimitive.Root
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) onClose();
      }}
    >
      <DialogPrimitive.Portal>
        {/* Dimmed overlay behind the panel. */}
        <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />

        {/* Centered content panel. Without a description, aria-describedby
            must be set to undefined to override the Radix default, which
            would otherwise point at a non-existent element. */}
        <DialogPrimitive.Content
          {...(description === undefined
            ? { "aria-describedby": undefined }
            : {})}
          className={cn(
            "fixed left-1/2 top-1/2 z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2",
            "rounded-lg border border-gray-200 bg-white shadow-lg",
            "data-[state=open]:animate-in data-[state=closed]:animate-out",
            "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
            "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
            className,
          )}
        >
          {/* Header row: title on the left, close button on the right. */}
          <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <DialogPrimitive.Title className="text-sm font-semibold text-gray-800">
              {title}
            </DialogPrimitive.Title>

            {/* Screen-reader-only description wired to aria-describedby. */}
            {description !== undefined && (
              <DialogPrimitive.Description className="sr-only">
                {description}
              </DialogPrimitive.Description>
            )}

            {/* Close button: Radix Close triggers onOpenChange(false). */}
            <DialogPrimitive.Close
              className="cursor-pointer rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-300"
              aria-label="Close dialog"
            >
              <X size={16} />
            </DialogPrimitive.Close>
          </div>

          {/* Panel body. */}
          <div className="px-5 py-4">{children}</div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}
