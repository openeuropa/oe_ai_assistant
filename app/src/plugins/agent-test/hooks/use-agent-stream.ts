/**
 * Hook that manages the SSE stream for the agent-test plugin.
 *
 * Handles sending messages, parsing SSE events, updating the
 * store with plan steps and draft results, and resetting state.
 */
import { useCallback, useRef } from "react";
import { postChat, postReset } from "../api/agent-test-api";
import {
  getAgentTestState,
  setAgentTestState,
  useAgentTestSlice,
} from "../store";
import type { PlanStep, SseEvent } from "../types";

export function useAgentStream() {
  const { messages, plan, draft, status, error } = useAgentTestSlice();
  const readerRef = useRef<ReadableStreamDefaultReader<Uint8Array> | null>(
    null,
  );

  const send = useCallback((message: string) => {
    const current = getAgentTestState();
    // Add user message to the list.
    const updatedMessages = [
      ...current.messages,
      { role: "user" as const, text: message },
    ];
    setAgentTestState({
      messages: updatedMessages,
      plan: [],
      draft: null,
      error: null,
      status: "streaming",
    });

    (async () => {
      try {
        const response = await postChat(message);
        const reader = response.body!.getReader();
        readerRef.current = reader;
        const decoder = new TextDecoder();
        let buffer = "";
        // Accumulate text-delta chunks into the assistant response.
        let assistantText = "";

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });

          // SSE frames are separated by \n\n.
          const frames = buffer.split("\n\n");
          buffer = frames.pop()!;

          for (const frame of frames) {
            const dataLine = frame
              .split("\n")
              .find((l) => l.startsWith("data: "));
            if (!dataLine) continue;

            const raw = dataLine.slice(6);
            if (raw === "[DONE]") continue;

            let event: SseEvent;
            try {
              event = JSON.parse(raw) as SseEvent;
            } catch {
              continue;
            }

            handleEvent(event, assistantText, (text) => {
              assistantText = text;
            });
          }
        }

        // Finalize: move streaming text into messages and clear it.
        const state = getAgentTestState();
        if (assistantText !== "") {
          setAgentTestState({
            messages: [
              ...state.messages,
              { role: "assistant", text: assistantText },
            ],
            streamingText: "",
            status: "done",
          });
        } else {
          setAgentTestState({ streamingText: "", status: "done" });
        }
      } catch (err) {
        setAgentTestState({
          error: err instanceof Error ? err.message : "Stream failed",
          status: "error",
        });
      }
    })();
  }, []);

  const reset = useCallback(async () => {
    readerRef.current?.cancel();
    readerRef.current = null;
    try {
      await postReset();
    } catch {
      // Ignore reset errors.
    }
    setAgentTestState({
      messages: [],
      streamingText: "",
      plan: [],
      draft: null,
      status: "idle",
      error: null,
    });
  }, []);

  const { streamingText } = useAgentTestSlice();
  return { messages, streamingText, plan, draft, status, error, send, reset };
}

/**
 * Processes a single SSE event and updates the store.
 */
function handleEvent(
  event: SseEvent,
  assistantText: string,
  setAssistantText: (text: string) => void,
): void {
  switch (event.type) {
    case "text-delta": {
      const updated = assistantText + event.textDelta;
      setAssistantText(updated);
      // Push to store so the UI renders progressively.
      setAgentTestState({ streamingText: updated });
      break;
    }

    case "data-plan":
      setAgentTestState({ plan: event.data as PlanStep[] });
      break;

    case "start-step": {
      if (!event.stepId) break;
      const state = getAgentTestState();
      setAgentTestState({
        plan: state.plan.map((step) =>
          step.stepId === event.stepId
            ? { ...step, status: "in_progress" }
            : step,
        ),
      });
      break;
    }

    case "finish-step": {
      if (!event.stepId) break;
      const state = getAgentTestState();
      setAgentTestState({
        plan: state.plan.map((step) =>
          step.stepId === event.stepId ? { ...step, status: "done" } : step,
        ),
      });
      break;
    }

    case "data-drafted-fields":
      setAgentTestState({ draft: event.data });
      break;

    case "error":
      setAgentTestState({
        error: event.errorText,
        status: "error",
      });
      break;
  }
}
