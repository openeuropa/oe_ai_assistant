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
 * POST /api/plugins/drafting/set-template - Validate selected template
 * POST /api/plugins/drafting/add-document - Mock document upload
 * POST /api/plugins/drafting/list-documents - List mock documents
 * POST /api/plugins/drafting/remove-document - Remove a mock document
 */

import { readFileSync } from "node:fs";
import { join } from "node:path";
import { Router } from "express";
import { sendDone, sendEvent, setupSseResponse } from "../lib/sse";
import type {
  ChatOptions,
  DraftingService,
} from "../services/drafting-service";

interface MockDocument {
  id: string;
  title: string;
  meta: {
    type: string;
    size: number;
  };
}

interface MultipartFileInfo {
  filename: string;
  size: number;
}

const initialMockDocuments: MockDocument[] = [
  {
    id: "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
    title: "EU AI Act briefing note.pdf",
    meta: { type: "pdf", size: 245760 },
  },
  {
    id: "c9bf9e57-1685-4c89-bafb-ff5af830be8a",
    title: "Stakeholder comments.docx",
    meta: { type: "docx", size: 98304 },
  },
];

function extensionFromFilename(filename: string): string {
  const extension = filename.split(".").pop();
  return extension && extension !== filename ? extension.toLowerCase() : "file";
}

async function readRequestBody(req: NodeJS.ReadableStream): Promise<Buffer> {
  const chunks: Buffer[] = [];
  for await (const chunk of req) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  }
  return Buffer.concat(chunks);
}

function parseMultipartUpload(
  body: Buffer,
  contentType: string,
): { sessionId: string; category: string; file: MultipartFileInfo | null } {
  const boundary = contentType.match(/boundary=([^;]+)/)?.[1];
  if (!boundary) {
    return { sessionId: "", category: "", file: null };
  }

  const parts = body.toString("latin1").split(`--${boundary}`);
  let sessionId = "";
  let category = "";
  let file: MultipartFileInfo | null = null;

  for (const part of parts) {
    const [rawHeaders, ...rawBodyParts] = part.split("\r\n\r\n");
    if (!rawHeaders || rawBodyParts.length === 0) {
      continue;
    }
    const content = rawBodyParts.join("\r\n\r\n").replace(/\r\n$/, "");
    const name = rawHeaders.match(/name="([^"]+)"/)?.[1] ?? "";
    const filename = rawHeaders.match(/filename="([^"]*)"/)?.[1];

    if (filename !== undefined && name === "file") {
      file = {
        filename: filename || "document",
        size: Buffer.byteLength(content, "latin1"),
      };
    } else if (name === "sessionId") {
      sessionId = content;
    } else if (name === "category") {
      category = content;
    }
  }

  return { sessionId, category, file };
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
  const documentsBySession = new Map<string, MockDocument[]>([
    ["dev-session", initialMockDocuments],
  ]);

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

    const sessionId = body.sessionId as string | undefined;
    if (!sessionId) {
      res
        .status(400)
        .json({ code: "bad_request", message: "sessionId is required" });
      return;
    }

    // The real backend derives the bundle from the session; standalone dev
    // has no session, so it drafts for the one bundle that has a fixture.
    const entityTypeId = "node";
    const bundle = "oe_news";

    // Load the content type schema from a static JSON file.
    const schema = loadSchema(bundle);
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
        sessionId,
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

    // Send the [DONE] sentinel to signal stream termination.
    sendDone(res);
    res.end();
  });

  /** POST /reset - Clear the conversation for a session. */
  router.post("/reset", (req, res) => {
    const sessionId = (req.body as { sessionId?: string })?.sessionId;
    if (!sessionId) {
      res
        .status(400)
        .json({ code: "bad_request", message: "sessionId is required" });
      return;
    }
    res.json(service.reset(sessionId));
  });

  /** POST /get-messages - Return the persisted transcript for a session. */
  router.post("/get-messages", (req, res) => {
    const sessionId = (req.body as { sessionId?: string })?.sessionId;
    if (!sessionId) {
      res
        .status(400)
        .json({ code: "bad_request", message: "sessionId is required" });
      return;
    }
    res.json({ messages: service.getMessages(sessionId) });
  });

  /** POST /save - Mock: save a session draft version as a node. */
  router.post("/save", (req, res) => {
    const { sessionId, version } = req.body as {
      sessionId?: string;
      version?: number;
    };

    if (!sessionId || typeof version !== "number") {
      res.status(400).json({
        code: "bad_request",
        message: "sessionId and version are required",
      });
      return;
    }

    const result = service.save({ sessionId, version });
    if (!result) {
      res.status(400).json({
        code: "invalid_request",
        message: `Draft ${version} does not exist in this session.`,
      });
      return;
    }
    res.json(result);
  });

  /**
   * POST /set-tone - Receive selected drafting tone.
   *
   * This standalone mock mirrors the Drupal route so the frontend can be
   * exercised locally while the backend implementation evolves separately.
   */
  router.post("/set-tone", (req, res) => {
    const { toneId } = req.body as {
      toneId?: string;
    };

    if (!toneId) {
      res.status(400).json({
        code: "bad_request",
        message: "toneId is required",
      });
      return;
    }

    console.info("[drafting] set-tone", { toneId });
    // Delay the response so the Save spinner is visible during local
    // development. The real backend responds as soon as it persists.
    setTimeout(() => {
      res.json({ status: "ok" });
    }, 1000);
  });

  /**
   * POST /set-template - Receive selected drafting template.
   *
   * This standalone mock mirrors the Drupal route so the frontend can be
   * exercised locally while the backend implementation evolves separately.
   */
  router.post("/set-template", (req, res) => {
    const { template } = req.body as {
      template?: string;
    };

    if (!template) {
      res.status(400).json({
        code: "bad_request",
        message: "template is required",
      });
      return;
    }

    console.info("[drafting] set-template", { template });
    // Delay the response so the Save spinner is visible during local
    // development. The real backend responds as soon as it persists.
    setTimeout(() => {
      res.json({ status: "ok" });
    }, 1000);
  });

  /** POST /add-document - Store a mock document for the current session. */
  router.post("/add-document", async (req, res) => {
    const { sessionId, category, file } = parseMultipartUpload(
      await readRequestBody(req),
      req.headers["content-type"] ?? "",
    );

    if (!sessionId || category !== "context" || file === null) {
      res.status(400).json({
        code: "bad_request",
        message: "sessionId, category, and file are required",
      });
      return;
    }

    const document: MockDocument = {
      id: `mock-document-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      title: file.filename,
      meta: {
        type: extensionFromFilename(file.filename),
        size: file.size,
      },
    };
    const documents = documentsBySession.get(sessionId) ?? [];
    documentsBySession.set(sessionId, [...documents, document]);

    res.json({ document });
  });

  /** POST /list-documents - Return mock documents for a session. */
  router.post("/list-documents", (req, res) => {
    const { sessionId, category } = req.body as {
      sessionId?: string;
      category?: string;
    };

    if (!sessionId || category !== "context") {
      res.status(400).json({
        code: "bad_request",
        message: "sessionId and category are required",
      });
      return;
    }

    res.json({ documents: documentsBySession.get(sessionId) ?? [] });
  });

  /** POST /remove-document - Remove a mock document for a session. */
  router.post("/remove-document", (req, res) => {
    const { sessionId, documentId } = req.body as {
      sessionId?: string;
      documentId?: string;
    };

    if (!sessionId || !documentId) {
      res.status(400).json({
        code: "bad_request",
        message: "sessionId and documentId are required",
      });
      return;
    }

    const documents = documentsBySession.get(sessionId) ?? [];
    if (!documents.some((document) => document.id === documentId)) {
      res.status(404).json({
        code: "not_found",
        message: "document was not found for this session",
      });
      return;
    }

    documentsBySession.set(
      sessionId,
      documents.filter((document) => document.id !== documentId),
    );
    res.json({ status: "ok" });
  });

  return router;
}
