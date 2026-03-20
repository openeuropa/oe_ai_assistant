/**
 * Drafting plugin routes.
 *
 * Thin Express route handlers that delegate to DraftingService.
 * The chat endpoint is real (calls Mistral); save is mocked.
 *
 * POST /api/plugins/drafting/chat   - SSE stream (real LLM)
 * POST /api/plugins/drafting/reset  - Clear conversation
 * POST /api/plugins/drafting/save   - Mock save
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import { Router } from "express";
import { sendEvent, setupSseResponse } from "../lib/sse";
import type {
  ChatOptions,
  DraftingService,
} from "../services/drafting-service";

/**
 * Loads a static content type schema by bundle name.
 * Returns null if no schema file exists for the bundle.
 */
function loadSchema(bundle: string): object | null {
  try {
    const schemaPath = join(
      import.meta.dirname,
      "..",
      "schemas",
      `${bundle}.json`,
    );
    const content = readFileSync(schemaPath, "utf-8");
    return JSON.parse(content) as object;
  } catch {
    return null;
  }
}

/**
 * Creates the drafting router. Receives a DraftingService
 * instance created at server startup.
 */
export function createDraftingRouter(
  service: DraftingService,
): Router {
  const router = Router();

  /**
   * POST /chat - Stream AI chat responses via SSE.
   *
   * Parses the AG-UI request format, loads the content type
   * schema, and delegates to DraftingService.chat(). Yielded
   * AG-UI events are written to the SSE response. Each event
   * flushes individually for progressive streaming.
   */
  router.post("/chat", async (req, res) => {
    // Parse request body.
    const body = req.body as Record<string, unknown>;

    // Extract user message from AG-UI protocol format.
    let message = (body.message as string) ?? "";
    if (!message && Array.isArray(body.messages)) {
      const userMessages = (
        body.messages as Array<{
          role: string;
          content: string | Array<{ text?: string }>;
        }>
      ).filter((m) => m.role === "user");
      const last = userMessages[userMessages.length - 1];
      if (last) {
        message = Array.isArray(last.content)
          ? last.content.map((p) => p.text ?? "").join("")
          : (last.content ?? "");
      }
    }

    if (!message) {
      res
        .status(400)
        .json({ code: "bad_request", message: "message is required" });
      return;
    }

    const threadId = body.threadId as string | undefined;
    const forwardedProps =
      (body.forwardedProps as Record<string, string>) ?? {};
    const entityTypeId =
      forwardedProps.entityTypeId ??
      (body.entityTypeId as string) ??
      "node";
    const bundle =
      forwardedProps.bundle ?? (body.bundle as string) ?? "";

    // Load the content type schema from a static JSON file.
    const schema = bundle ? loadSchema(bundle) : null;
    console.log(
      "[drafting] bundle=%s schema=%s",
      bundle,
      schema ? "loaded" : "NOT FOUND",
    );

    // Set up SSE response.
    setupSseResponse(res);

    // Stream AG-UI events from the service to the response.
    // Each event is written and flushed individually for
    // progressive streaming.
    try {
      for await (const event of service.chat({
        message,
        threadId,
        entityTypeId,
        bundle,
        schema: schema as ChatOptions["schema"],
      })) {
        sendEvent(res, event);
        // Yield to the event loop after each write so the
        // SSE frame flushes to the client individually.
        await new Promise((r) => setTimeout(r, 0));
      }
    } catch (err) {
      console.error("SSE stream error:", err);
    }

    res.end();
  });

  /** POST /reset - Clear conversation thread. */
  router.post("/reset", (req, res) => {
    const threadId = (req.body as { threadId?: string })
      ?.threadId;
    res.json(service.reset(threadId));
  });

  /** POST /save - Mock: save draft as node. */
  router.post("/save", (req, res) => {
    const { entityTypeId, bundle, fields } = req.body as {
      entityTypeId?: string;
      bundle?: string;
      fields?: Record<string, unknown>;
    };

    if (!entityTypeId || !bundle || !fields) {
      res.status(400).json({
        code: "bad_request",
        message:
          "entityTypeId, bundle, and fields are required",
      });
      return;
    }

    res.json(
      service.save({ entityTypeId, bundle, fields }),
    );
  });

  return router;
}
