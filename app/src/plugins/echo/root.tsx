/**
 * Echo plugin root component.
 *
 * Dev-only plugin that sends a message and receives it back
 * word-by-word via a mock SSE stream. Useful for testing:
 * - Form submission
 * - SSE streaming consumption
 * - Loading/error states
 * - Real-time UI updates during a stream
 */

import { EchoForm } from "./components/echo-form";
import { EchoResponse } from "./components/echo-response";
import { useEchoStream } from "./hooks/use-echo-stream";

export default function EchoRoot() {
  const { words, status, error, send, reset } = useEchoStream();

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-8">
      <p className="text-sm text-gray-500">
        Type a message and watch it stream back word-by-word via mock SSE.
      </p>
      <EchoForm onSubmit={send} isStreaming={status === "streaming"} />
      <EchoResponse
        words={words}
        status={status}
        error={error}
        onReset={reset}
      />
    </div>
  );
}
