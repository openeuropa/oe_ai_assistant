/**
 * Exit control for the session header.
 *
 * Idle: opens a confirmation dialog stating the session has been saved;
 * confirming navigates the browser to the host-provided exit URL.
 * Pending work (a stream, tool call, or save in flight, or unsent
 * composer input reported by any plugin): opens a wait dialog and never
 * navigates. Also warns on tab close or reload while work is pending.
 * Hidden when the host supplies no exit URL.
 */

import { LogOut } from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { getConfig } from "@/config";
import { useHasPendingWork } from "@/store";

export function ExitControl() {
  const exitUrl = getConfig().exitUrl.trim();
  const hasPendingWork = useHasPendingWork();
  const [dialog, setDialog] = useState<"none" | "confirm" | "wait">("none");

  // Warn on tab close or reload while work is pending.
  useEffect(() => {
    if (!hasPendingWork) return;
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [hasPendingWork]);

  // Without an exit target there is nothing to render.
  if (!exitUrl) {
    return null;
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        className="cursor-pointer"
        onClick={() => setDialog(hasPendingWork ? "wait" : "confirm")}
      >
        <LogOut data-icon="inline-start" />
        Exit session
      </Button>

      {/* Idle path: confirm that the session is saved, then navigate. */}
      <Dialog
        open={dialog === "confirm"}
        onClose={() => setDialog("none")}
        title="Exit session"
      >
        <p className="text-sm text-gray-600">
          Your session has been saved. You can come back to it at any time.
        </p>
        <div className="mt-4 flex justify-end gap-2">
          <Button
            variant="outline"
            className="cursor-pointer"
            onClick={() => setDialog("none")}
          >
            Stay
          </Button>
          <Button
            className="cursor-pointer"
            onClick={() => window.location.assign(exitUrl)}
          >
            Exit
          </Button>
        </div>
      </Dialog>

      {/* Pending path: block navigation until the assistant is done. */}
      <Dialog
        open={dialog === "wait"}
        onClose={() => setDialog("none")}
        title="Please wait"
      >
        <p className="text-sm text-gray-600">
          The assistant is still working or you have unsent input. Please wait
          until it finishes before leaving the session.
        </p>
        <div className="mt-4 flex justify-end">
          <Button className="cursor-pointer" onClick={() => setDialog("none")}>
            OK
          </Button>
        </div>
      </Dialog>
    </>
  );
}
