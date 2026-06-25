/**
 * Drafting plugin routes.
 *
 * Thin Express route handlers that delegate to the active
 * drafting service. Depending on the selected dev mode, the
 * chat endpoint is either fixture-backed mock streaming or
 * Mistral-backed integration streaming. Save remains mocked.
 *
 * POST /api/plugins/drafting/chat   - SSE stream (mock or Mistral)
 * POST /api/plugins/drafting/reset  - Clear conversation
 * POST /api/plugins/drafting/save   - Mock save
 * POST /api/plugins/drafting/set-tone - Validate selected tone
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import { Router } from "express";
import { sendDone, sendEvent, setupSseResponse } from "../lib/sse";
import type {
  ChatOptions,
  DraftingEditorialContext,
  DraftingSaveSessionPayload,
  DraftingService,
} from "../services/drafting-service";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function parseContext(value: unknown): DraftingEditorialContext | undefined {
  if (!isRecord(value)) {
    return undefined;
  }

  return {
    audienceId:
      typeof value.audienceId === "string" ? value.audienceId : undefined,
    toneId: typeof value.toneId === "string" ? value.toneId : undefined,
  };
}

function readString(value: unknown): string | undefined {
  return typeof value === "string" ? value : undefined;
}

/**
 * Loads a content type schema fixture by bundle name.
 *
 * Uses the EntityJsonSchemaComposer format (JSON Schema with
 * flat properties map), stored in fixtures/content-schema-{bundle}.json.
 */
function loadSchema(bundle: string): object | null {
  try {
    const schemaPath = join(
      import.meta.dirname,
      "..",
      "fixtures",
      `content-schema-${bundle}.json`,
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
export function createDraftingRouter(service: DraftingService): Router {
  const router = Router();

  /**
   * POST /chat - Stream AI chat responses via SSE.
   *
   * Parses the request body (supports both legacy AG-UI format
   * and the new Data Stream Protocol format), loads the content
   * type schema, and delegates to DraftingService.chat(). Yielded
   * events are written to the SSE response. Each event flushes
   * individually for progressive streaming.
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

    const threadId = readString(body.threadId);
    const forwardedProps = isRecord(body.forwardedProps)
      ? body.forwardedProps
      : {};
    const entityTypeId =
      readString(forwardedProps.entityTypeId) ??
      readString(body.entityTypeId) ??
      "node";
    const bundle =
      readString(forwardedProps.bundle) ?? readString(body.bundle) ?? "";
    const context =
      parseContext(body.context) ?? parseContext(forwardedProps.context);

    // Load the content type schema from a static JSON file.
    const schema = bundle ? loadSchema(bundle) : null;
    console.log(
      "[drafting] bundle=%s schema=%s",
      bundle,
      schema ? "loaded" : "NOT FOUND",
    );

    // Set up SSE response.
    setupSseResponse(res);

    // Stream Data Stream Protocol events from the service to
    // the response. Each event is written and flushed individually
    // for progressive streaming.
    try {
      for await (const event of service.chat({
        message,
        threadId,
        entityTypeId,
        bundle,
        schema: schema as ChatOptions["schema"],
        context,
      })) {
        sendEvent(res, event);
        // Yield to the event loop after each write so the
        // SSE frame flushes to the client individually.
        await new Promise((r) => setTimeout(r, 0));
      }
    } catch (err) {
      console.error("SSE stream error:", err);
    }

    // Send the [DONE] sentinel to signal stream termination.
    sendDone(res);
    res.end();
  });

  /** POST /reset - Clear conversation thread. */
  router.post("/reset", (req, res) => {
    const threadId = (req.body as { threadId?: string })?.threadId;
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
        message: "entityTypeId, bundle, and fields are required",
      });
      return;
    }

    res.json(service.save({ entityTypeId, bundle, fields }));
  });

  /**
   * POST /set-tone - Receive selected drafting tone.
   *
   * This standalone mock mirrors the Drupal route so the frontend can be
   * exercised locally while the backend implementation evolves separately.
   */
  router.post("/set-tone", (req, res) => {
    const { context } = req.body as {
      context?: { toneId?: string };
    };

    if (!context?.toneId) {
      res.status(400).json({
        code: "bad_request",
        message: "context.toneId is required",
      });
      return;
    }

    console.info("[drafting] set-tone", context);
    res.json({ status: "ok" });
  });

  return router;
}
