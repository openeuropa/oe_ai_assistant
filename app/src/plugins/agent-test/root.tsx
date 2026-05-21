/**
 * Root component for the agent-test plugin.
 *
 * Simple chat UI that connects to the agent_test backend plugin.
 * Displays conversation messages, orchestration step progress,
 * and the consolidated draft result.
 */

import { RotateCcw, Send } from "lucide-react";
import { type FormEvent, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DraftResultView } from "./components/draft-result";
import { PlanSteps } from "./components/plan-steps";
import { useAgentStream } from "./hooks/use-agent-stream";

export default function AgentTestRoot() {
  const { messages, streamingText, plan, draft, status, error, send, reset } =
    useAgentStream();
  const [input, setInput] = useState("");
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const trimmed = input.trim();
    if (!trimmed || status === "streaming") return;
    setInput("");
    send(trimmed);
  };

  return (
    <div className="flex h-full flex-col">
      {/* Header */}
      <div className="flex items-center justify-between border-b px-4 py-2">
        <h2 className="text-sm font-semibold">Agent Test</h2>
        <Button variant="ghost" size="sm" onClick={reset} title="Reset">
          <RotateCcw className="h-4 w-4" />
        </Button>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-3">
        {messages.length === 0 && status === "idle" && (
          <p className="text-sm text-muted-foreground">
            Start chatting to build context, then ask to draft content.
          </p>
        )}

        {messages.map((msg) => (
          <div
            key={`${msg.role}-${msg.text.slice(0, 20)}`}
            className={`text-sm ${
              msg.role === "user" ? "text-right" : "text-left"
            }`}
          >
            <span
              className={`inline-block max-w-[80%] rounded-lg px-3 py-2 ${
                msg.role === "user"
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted"
              }`}
            >
              {msg.text}
            </span>
          </div>
        ))}

        {/* Plan steps (shown during orchestration) */}
        <PlanSteps steps={plan} />

        {/* Draft result */}
        {draft && <DraftResultView draft={draft} />}

        {/* Live streaming text from the assistant. */}
        {streamingText && (
          <div className="text-sm text-left">
            <span className="inline-block max-w-[80%] rounded-lg bg-muted px-3 py-2">
              {streamingText}
              <span className="animate-pulse">|</span>
            </span>
          </div>
        )}

        {/* Error */}
        {error && <div className="text-sm text-red-500">Error: {error}</div>}

        <div ref={messagesEndRef} />
      </div>

      {/* Input */}
      <form
        onSubmit={handleSubmit}
        className="flex items-center gap-2 border-t px-4 py-2"
      >
        <Input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder="Type a message..."
          disabled={status === "streaming"}
          className="flex-1"
        />
        <Button
          type="submit"
          size="sm"
          disabled={status === "streaming" || !input.trim()}
        >
          <Send className="h-4 w-4" />
        </Button>
      </form>
    </div>
  );
}
