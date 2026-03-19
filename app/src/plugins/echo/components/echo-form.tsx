/**
 * Echo form.
 *
 * A text input and submit button for sending a message to the
 * mock echo API. Disabled while a stream is in progress.
 */

import { type FormEvent, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

interface EchoFormProps {
  /** Called when the user submits a message. */
  onSubmit: (message: string) => void;
  /** Whether a stream is currently in progress. */
  isStreaming: boolean;
}

export function EchoForm({ onSubmit, isStreaming }: EchoFormProps) {
  const [message, setMessage] = useState("");

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const trimmed = message.trim();
    if (trimmed.length === 0) return;
    onSubmit(trimmed);
    setMessage("");
  }

  return (
    <form onSubmit={handleSubmit} className="flex gap-2">
      <Input
        type="text"
        value={message}
        onChange={(e) => setMessage(e.target.value)}
        placeholder="Type a message to echo..."
        disabled={isStreaming}
        className="flex-1"
      />
      <Button
        type="submit"
        disabled={isStreaming || message.trim().length === 0}
      >
        {isStreaming ? "Streaming..." : "Send"}
      </Button>
    </form>
  );
}
