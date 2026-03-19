/**
 * Mock drafting endpoint for standalone dev (no Drupal needed).
 *
 * POST /api/plugins/drafting/chat
 *
 * Two behaviours based on the user's message:
 * - Contains "generate a draft": triggers the full agent flow
 *   with tool calls, loading pauses, streamed text, and a
 *   STATE_SNAPSHOT that populates the content table.
 * - Anything else: streams a conversational reply char by char.
 *
 * POST /api/plugins/drafting/reset
 *
 * Returns a new thread ID.
 */

import { randomUUID } from "node:crypto";
import { Router } from "express";
import { SSE_CHUNK_DELAY_MS } from "../config";

export const draftingRouter = Router();

/** Reads the raw request body and parses it as JSON. */
function readJsonBody(req: import("express").Request): Promise<unknown> {
  return new Promise((resolve, reject) => {
    let data = "";
    req.on("data", (chunk: Buffer) => {
      data += chunk.toString();
    });
    req.on("end", () => {
      try {
        resolve(JSON.parse(data));
      } catch {
        reject(new Error("Invalid JSON"));
      }
    });
    req.on("error", reject);
  });
}

/** Sends a single AG-UI event as an SSE frame. */
function sendEvent(
  res: import("express").Response,
  data: Record<string, unknown>,
): void {
  res.write(`data: ${JSON.stringify(data)}\n\n`);
}

/** Delay helper. */
function delay(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

/**
 * Streams a text string character by character as AG-UI
 * TEXT_MESSAGE_CONTENT events. Returns true if cancelled.
 */
async function streamText(
  res: import("express").Response,
  messageId: string,
  text: string,
  cancelled: () => boolean,
): Promise<boolean> {
  sendEvent(res, {
    type: "TEXT_MESSAGE_START",
    messageId,
    role: "assistant",
  });

  for (const char of text) {
    if (cancelled()) return true;
    sendEvent(res, {
      type: "TEXT_MESSAGE_CONTENT",
      messageId,
      delta: char,
    });
    await delay(SSE_CHUNK_DELAY_MS);
  }

  if (cancelled()) return true;
  sendEvent(res, { type: "TEXT_MESSAGE_END", messageId });
  return false;
}

/** Random conversational replies for non-draft messages. */
const CHAT_REPLIES = [
  "Sure, I can help with that! Could you tell me more about " +
    "the topic you'd like to cover? For example, what audience " +
    "are you targeting and what tone should the content have?\n\n" +
    "When you're ready, say \"generate a draft\" and I'll create " +
    "structured content for all the fields.",

  "That's an interesting topic. Before I generate the draft, " +
    "let me ask a few questions:\n\n" +
    "1. What is the main message you want to convey?\n" +
    "2. Are there any key facts or quotes to include?\n" +
    "3. What length are you aiming for?\n\n" +
    'Once we\'ve discussed the details, say "generate a draft" ' +
    "to produce the structured content.",

  "I understand. I'll keep that in mind when drafting the content. " +
    "Is there anything else you'd like to specify before I start? " +
    "You can mention preferred keywords, a specific angle, or any " +
    "constraints.\n\n" +
    'Say "generate a draft" whenever you\'re ready.',

  "Good point! I've noted that. Let me know if you have any " +
    "other preferences for the content. Things like:\n\n" +
    "- Formal or informal tone\n" +
    "- Specific sections to emphasise\n" +
    "- Any references or sources to cite\n\n" +
    'Just say "generate a draft" when you want me to produce ' +
    "the structured fields.",
];

/** Simulated draft fields that the agent produces. */
const DEMO_FIELDS: Record<string, Record<string, unknown>> = {
  title: {
    label: "Title",
    value: "EU AI Act: A New Era for Artificial Intelligence Regulation",
    type: "textfield",
  },
  oe_summary: {
    label: "Introduction",
    value:
      "The European Union has formally adopted the AI Act, establishing " +
      "the world's first comprehensive legal framework for artificial " +
      "intelligence.",
    type: "textarea",
  },
  body: {
    label: "Body text",
    value:
      "The AI Act classifies AI systems into four risk categories: " +
      "unacceptable, high, limited, and minimal risk. Systems deemed " +
      "to pose unacceptable risks, such as social scoring by " +
      "governments, are banned outright.\n\n" +
      "High-risk systems must meet strict requirements including " +
      "human oversight, transparency, and robustness before they can " +
      "be deployed in the EU market.",
    type: "textarea",
  },
  oe_teaser: {
    label: "Teaser",
    value:
      "Landmark EU legislation establishes the world's first " +
      "comprehensive framework for AI regulation.",
    type: "textarea",
  },
  oe_content_short_title: {
    label: "Alternative title",
    value: "EU AI Act",
    type: "textfield",
  },
  oe_publication_date: {
    label: "Publication date",
    value: "2026-03-17",
    type: "date",
  },
  oe_news_contacts: {
    label: "Contacts",
    value: "",
    type: "inline_form",
    inlineEntities: [
      {
        bundle: "oe_general",
        bundleLabel: "General",
        fields: {
          name: { label: "Name", value: "European Commission - DG CONNECT" },
          oe_organisation: {
            label: "Organisation",
            value:
              "Directorate-General for Communications Networks, Content and Technology",
          },
          oe_email: { label: "Email", value: "press@ec.europa.eu" },
          oe_phone: { label: "Phone number", value: "+32 2 299 11 11" },
          oe_office: { label: "Office", value: "BERL 04/347" },
          oe_website: {
            label: "Website",
            value: "https://digital-strategy.ec.europa.eu",
          },
        },
      },
      {
        bundle: "oe_general",
        bundleLabel: "General",
        fields: {
          name: { label: "Name", value: "EU AI Office" },
          oe_organisation: {
            label: "Organisation",
            value: "European AI Office",
          },
          oe_email: { label: "Email", value: "ai-office@ec.europa.eu" },
          oe_phone: { label: "Phone number", value: "+32 2 296 00 00" },
          oe_website: {
            label: "Website",
            value:
              "https://digital-strategy.ec.europa.eu/en/policies/ai-office",
          },
        },
      },
    ],
  },
};

draftingRouter.post("/chat", async (req, res) => {
  const body = (await readJsonBody(req)) as {
    message?: string;
    threadId?: string;
    entityTypeId?: string;
    bundle?: string;
    schema?: Record<string, unknown>;
    messages?: Array<{ role: string; content: string }>;
  };

  const message =
    body.message ??
    body.messages?.filter((m) => m.role === "user").pop()?.content ??
    "";

  if (!message || typeof message !== "string") {
    res
      .status(400)
      .json({ code: "bad_request", message: "message is required" });
    return;
  }

  res.socket?.setNoDelay(true);
  res.writeHead(200, {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache",
    Connection: "keep-alive",
    "X-Accel-Buffering": "no",
  });

  const runId = randomUUID();
  const threadId = body.threadId ?? randomUUID();
  let cancelled = false;

  req.on("close", () => {
    cancelled = true;
  });

  const isCancelled = () => cancelled;

  sendEvent(res, { type: "RUN_STARTED", runId, threadId });

  // Determine which flow to trigger based on the message.
  const lowerMessage = message.toLowerCase();
  const shouldDraft = lowerMessage.includes("generate a draft");
  const shouldSave = lowerMessage.includes("save");

  // Parse field names from "[fields:title,body,oe_teaser]" tag.
  const fieldsMatch = message.match(/\[fields:([^\]]+)\]/);
  const shouldRegenerate = fieldsMatch !== null;
  const regenFieldNames = fieldsMatch ? fieldsMatch[1]!.split(",") : [];

  if (shouldRegenerate) {
    // --- Regenerate flow: re-draft specific fields ---

    const regenLabels = regenFieldNames
      .map((name) => DEMO_FIELDS[name]?.label ?? name)
      .join(", ");

    const regenIntroId = randomUUID();
    await streamText(
      res,
      regenIntroId,
      `I'll regenerate ${regenLabels} with a fresh take.`,
      isCancelled,
    );
    if (cancelled) return res.end();

    // Tool call: regenerate_fields.
    const regenToolId = randomUUID();
    sendEvent(res, {
      type: "TOOL_CALL_START",
      toolCallId: regenToolId,
      toolCallName: "regenerate_fields",
    });
    sendEvent(res, {
      type: "TOOL_CALL_ARGS",
      toolCallId: regenToolId,
      delta: JSON.stringify({ fields: regenFieldNames }),
    });
    sendEvent(res, {
      type: "TOOL_CALL_END",
      toolCallId: regenToolId,
    });

    await delay(1500);
    if (cancelled) return res.end();

    sendEvent(res, {
      type: "TOOL_CALL_RESULT",
      messageId: randomUUID(),
      toolCallId: regenToolId,
      content: `${regenFieldNames.length} fields regenerated.`,
    });

    // Build updated fields with new values.
    const updatedFields = { ...DEMO_FIELDS };
    for (const name of regenFieldNames) {
      if (updatedFields[name]) {
        updatedFields[name] = {
          ...updatedFields[name]!,
          value: `[Regenerated] ${updatedFields[name]!.value}`,
        };
      }
    }

    // Push updated state.
    sendEvent(res, {
      type: "STATE_SNAPSHOT",
      snapshot: { draftedFields: updatedFields },
    });

    const regenConfirmId = randomUUID();
    await streamText(
      res,
      regenConfirmId,
      `Done! I've regenerated ${regenLabels}. Check the updated values on the right.`,
      isCancelled,
    );
  } else if (shouldSave) {
    // --- Save flow: tool call to save draft revision ---

    const saveIntroId = randomUUID();
    await streamText(
      res,
      saveIntroId,
      "Saving your draft as a new unpublished revision...",
      isCancelled,
    );
    if (cancelled) return res.end();

    const saveToolId = randomUUID();
    sendEvent(res, {
      type: "TOOL_CALL_START",
      toolCallId: saveToolId,
      toolCallName: "save_draft_revision",
    });
    sendEvent(res, {
      type: "TOOL_CALL_ARGS",
      toolCallId: saveToolId,
      delta: JSON.stringify({ status: "draft", fields: 6 }),
    });
    sendEvent(res, { type: "TOOL_CALL_END", toolCallId: saveToolId });

    // Simulate save time.
    await delay(1500);
    if (cancelled) return res.end();

    sendEvent(res, {
      type: "TOOL_CALL_RESULT",
      messageId: randomUUID(),
      toolCallId: saveToolId,
      content: "Draft revision saved successfully.",
    });

    const saveConfirmId = randomUUID();
    await streamText(
      res,
      saveConfirmId,
      "Done! The draft has been saved as a new unpublished revision. " +
        "You can find it in the content revisions tab. The published " +
        "version remains unchanged.",
      isCancelled,
    );
  } else if (shouldDraft) {
    // --- Full agent flow: tool calls + text + state snapshot ---

    // Step 0: introductory text before tool calls.
    const introId = randomUUID();
    const introText =
      "Let me fetch the content schema first so I know which " +
      "fields to generate.";
    const introCancelled = await streamText(
      res,
      introId,
      introText,
      isCancelled,
    );
    if (introCancelled) return res.end();
    await delay(300);

    // Step 1: tool call to fetch the content schema.
    const schemaToolId = randomUUID();
    sendEvent(res, {
      type: "TOOL_CALL_START",
      toolCallId: schemaToolId,
      toolCallName: "get_content_schema",
    });
    sendEvent(res, {
      type: "TOOL_CALL_ARGS",
      toolCallId: schemaToolId,
      delta: JSON.stringify({ bundle: "oe_news" }),
    });
    sendEvent(res, { type: "TOOL_CALL_END", toolCallId: schemaToolId });

    // Simulate schema fetch time.
    await delay(1200);
    if (cancelled) return res.end();

    // Tool result: schema loaded successfully.
    sendEvent(res, {
      type: "TOOL_CALL_RESULT",
      messageId: randomUUID(),
      toolCallId: schemaToolId,
      content: "Schema loaded: 14 fields found for oe_news.",
    });

    // Step 2: stream the assistant's reasoning.
    const thinkingId = randomUUID();
    const thinkingText =
      "I've loaded the content schema for News. It has fields for " +
      "title, introduction, body text, teaser, alternative title, " +
      "and publication date. Let me generate content for each one.";

    const didCancel = await streamText(
      res,
      thinkingId,
      thinkingText,
      isCancelled,
    );
    if (didCancel) return res.end();

    // Pause to simulate generation work.
    await delay(1500);
    if (cancelled) return res.end();

    // Step 3: tool call to set field content.
    const setFieldsToolId = randomUUID();
    sendEvent(res, {
      type: "TOOL_CALL_START",
      toolCallId: setFieldsToolId,
      toolCallName: "set_field_content",
    });
    sendEvent(res, {
      type: "TOOL_CALL_ARGS",
      toolCallId: setFieldsToolId,
      delta: JSON.stringify(DEMO_FIELDS),
    });
    sendEvent(res, {
      type: "TOOL_CALL_END",
      toolCallId: setFieldsToolId,
    });

    // Simulate save time.
    await delay(800);
    if (cancelled) return res.end();

    // Tool result: fields saved successfully.
    sendEvent(res, {
      type: "TOOL_CALL_RESULT",
      messageId: randomUUID(),
      toolCallId: setFieldsToolId,
      content: "6 fields drafted successfully.",
    });

    // Step 4: push drafted fields via STATE_SNAPSHOT.
    sendEvent(res, {
      type: "STATE_SNAPSHOT",
      snapshot: { draftedFields: DEMO_FIELDS },
    });

    // Step 5: confirmation message.
    const confirmId = randomUUID();
    const confirmText =
      "\n\nDraft generated! Review the fields on the right panel. " +
      "You can reject any field and ask me to regenerate it.";

    await streamText(res, confirmId, confirmText, isCancelled);
  } else {
    // --- Conversational reply: random response ---

    const reply =
      CHAT_REPLIES[Math.floor(Math.random() * CHAT_REPLIES.length)]!;
    const replyId = randomUUID();

    await streamText(res, replyId, reply, isCancelled);
  }

  if (!cancelled) {
    sendEvent(res, { type: "RUN_FINISHED", runId, threadId });
  }
  res.end();
});

draftingRouter.post("/save", async (req, res) => {
  const body = (await readJsonBody(req)) as {
    entityTypeId?: string;
    bundle?: string;
    fields?: Record<string, unknown>;
  };

  if (!body.entityTypeId || !body.bundle || !body.fields) {
    res.status(400).json({
      code: "bad_request",
      message: "entityTypeId, bundle, and fields are required",
    });
    return;
  }

  // Simulate save delay.
  await delay(1500);

  const nodeId = String(Math.floor(Math.random() * 90000) + 10000);
  res.json({
    nodeId,
    previewUrl: `/node/${nodeId}/latest`,
  });
});

draftingRouter.post("/reset", async (_req, res) => {
  res.json({ threadId: randomUUID() });
});
