/**
 * Mock drafting endpoint for standalone dev (no Drupal needed).
 *
 * POST /api/plugins/drafting/chat
 *
 * Behaviours based on the user's message:
 * - Contains "draft": triggers the full agent flow with tool
 *   calls, streamed text, and progressive STATE_SNAPSHOTs.
 * - "rewrite <field>": rewrites a single field with fresh text.
 * - Anything else: streams a conversational reply char by char.
 *
 * POST /api/plugins/drafting/reset
 *
 * Returns a new thread ID.
 */

import { randomUUID } from "node:crypto";
import { Router } from "express";
import {
  FIELD_GAP_DELAY_MS,
  FIELD_WORD_DELAY_MS,
  SSE_CHUNK_DELAY_MS,
} from "../config";

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

/**
 * Determines whether a field should be streamed word-by-word.
 * Long text fields (textarea, html) get progressive streaming;
 * short fields (textfield, date, inline_form) arrive in one shot.
 */
function isStreamableField(field: Record<string, unknown>): boolean {
  const type = field.type as string;
  return (
    (type === "textarea" || type === "html") &&
    typeof field.value === "string" &&
    (field.value as string).length > 50
  );
}

/**
 * Last snapshot sent to the client. Used to diff against new fields
 * so only changed fields are streamed progressively.
 */
let lastSentFields: Record<string, Record<string, unknown>> = {};

/**
 * Streams fields progressively as a series of STATE_SNAPSHOT events.
 *
 * Compares each field against the last sent snapshot. Unchanged fields
 * are included immediately in the first snapshot; only changed fields
 * are streamed progressively (word-by-word for long text, whole for
 * short fields).
 *
 * Each snapshot is self-contained: it always includes all fields so
 * the adapter can replace state wholesale.
 */
async function streamFieldSnapshots(
  res: import("express").Response,
  fields: Record<string, Record<string, unknown>>,
  cancelled: () => boolean,
): Promise<boolean> {
  // Pre-allocate all keys in the input order so that JS object
  // iteration preserves the original field position. Changed
  // fields start as undefined and are filled in during streaming.
  const streamed: Record<string, Record<string, unknown> | undefined> = {};
  const changedNames: string[] = [];

  for (const [name, field] of Object.entries(fields)) {
    const prev = lastSentFields[name];
    if (prev && JSON.stringify(prev) === JSON.stringify(field)) {
      streamed[name] = field;
    } else {
      // Reserve the slot to preserve key order.
      streamed[name] = undefined;
      changedNames.push(name);
    }
  }

  /** Builds a snapshot object containing only the defined fields. */
  function buildSnapshot(): Record<string, Record<string, unknown>> {
    const out: Record<string, Record<string, unknown>> = {};
    for (const [k, v] of Object.entries(streamed)) {
      if (v !== undefined) out[k] = v;
    }
    return out;
  }

  // If there are unchanged fields, send one initial snapshot so
  // the frontend sees the complete baseline before streaming starts.
  if (changedNames.length > 0 && changedNames.length < Object.keys(fields).length) {
    sendEvent(res, {
      type: "STATE_SNAPSHOT",
      snapshot: { draftedFields: buildSnapshot() },
    });
  }

  // Stream only the changed fields progressively.
  for (const name of changedNames) {
    if (cancelled()) return true;
    const field = fields[name]!;

    if (isStreamableField(field)) {
      const fullValue = field.value as string;
      const words = fullValue.split(/(\s+)/);
      let partial = "";

      for (const word of words) {
        if (cancelled()) return true;
        partial += word;
        streamed[name] = { ...field, value: partial };
        sendEvent(res, {
          type: "STATE_SNAPSHOT",
          snapshot: { draftedFields: buildSnapshot() },
        });
        if (word.trim()) {
          await delay(FIELD_WORD_DELAY_MS);
        }
      }
    } else {
      streamed[name] = field;
      sendEvent(res, {
        type: "STATE_SNAPSHOT",
        snapshot: { draftedFields: buildSnapshot() },
      });
    }

    await delay(FIELD_GAP_DELAY_MS);
  }

  // Remember what we sent for the next diff.
  lastSentFields = buildSnapshot();
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
    'Once we\'ve discussed the details, say "draft" ' +
    "to produce the structured content.",

  "I understand. I'll keep that in mind when drafting the content. " +
    "Is there anything else you'd like to specify before I start? " +
    "You can mention preferred keywords, a specific angle, or any " +
    "constraints.\n\n" +
    'Say "draft" whenever you\'re ready.',

  "Good point! I've noted that. Let me know if you have any " +
    "other preferences for the content. Things like:\n\n" +
    "- Formal or informal tone\n" +
    "- Specific sections to emphasise\n" +
    "- Any references or sources to cite\n\n" +
    'Just say "draft" when you want me to produce ' +
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

// -- Lorem ipsum generator ---------------------------------------------------

/**
 * Word pool for generating random lorem ipsum text. Contains the
 * classic vocabulary shuffled into fresh combinations each time.
 */
const LOREM_WORDS = [
  "lorem", "ipsum", "dolor", "sit", "amet", "consectetur",
  "adipiscing", "elit", "sed", "do", "eiusmod", "tempor",
  "incididunt", "ut", "labore", "et", "dolore", "magna", "aliqua",
  "enim", "ad", "minim", "veniam", "quis", "nostrud",
  "exercitation", "ullamco", "laboris", "nisi", "aliquip", "ex",
  "ea", "commodo", "consequat", "duis", "aute", "irure", "in",
  "reprehenderit", "voluptate", "velit", "esse", "cillum", "fugiat",
  "nulla", "pariatur", "excepteur", "sint", "occaecat", "cupidatat",
  "non", "proident", "sunt", "culpa", "qui", "officia", "deserunt",
  "mollit", "anim", "id", "est", "laborum", "curabitur", "pretium",
  "tincidunt", "lacus", "gravida", "orci", "odio", "nullam",
  "varius", "turpis", "pharetra", "eros", "bibendum", "luctus",
  "felis", "sollicitudin", "mauris", "vivamus", "fermentum",
  "semper", "porta", "nunc", "diam", "blandit", "volutpat",
  "maecenas", "accumsan", "integer", "posuere", "morbi", "leo",
  "urna", "eleifend", "vitae", "metus", "pellentesque", "habitant",
  "tristique", "senectus", "netus", "fames", "egestas", "proin",
];

/** Picks a random word from the lorem pool. */
function randomWord(): string {
  return LOREM_WORDS[Math.floor(Math.random() * LOREM_WORDS.length)]!;
}

/**
 * Generates a lorem ipsum sentence of the given word count.
 * Capitalizes the first word and ends with a period.
 */
function loremSentence(wordCount: number): string {
  const words: string[] = [];
  for (let i = 0; i < wordCount; i++) {
    words.push(randomWord());
  }
  words[0] = words[0]!.charAt(0).toUpperCase() + words[0]!.slice(1);
  return words.join(" ") + ".";
}

/**
 * Generates a lorem ipsum paragraph with 3-5 sentences.
 */
function loremParagraph(): string {
  const count = 3 + Math.floor(Math.random() * 3);
  const sentences: string[] = [];
  for (let i = 0; i < count; i++) {
    sentences.push(loremSentence(8 + Math.floor(Math.random() * 8)));
  }
  return sentences.join(" ");
}

/**
 * Generates a rewrite value for a field based on its type.
 * Produces fresh random text every time it is called.
 */
function generateRewriteValue(
  field: Record<string, unknown>,
): Record<string, unknown> {
  const type = field.type as string;

  if (type === "textarea" || type === "html") {
    // Two paragraphs for long text fields.
    return { ...field, value: loremParagraph() + "\n\n" + loremParagraph() };
  }
  if (type === "textfield") {
    // Short title-like text: 4-7 words, no period.
    const count = 4 + Math.floor(Math.random() * 4);
    const words: string[] = [];
    for (let i = 0; i < count; i++) {
      const w = randomWord();
      words.push(w.charAt(0).toUpperCase() + w.slice(1));
    }
    return { ...field, value: words.join(" ") };
  }
  if (type === "date") {
    // Random date in the next 30 days.
    const d = new Date();
    d.setDate(d.getDate() + Math.floor(Math.random() * 30) + 1);
    return { ...field, value: d.toISOString().slice(0, 10) };
  }
  // Fallback: return unchanged.
  return field;
}

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
  const shouldDraft = lowerMessage.includes("draft");
  const shouldSave = lowerMessage.includes("save");

  // Parse "rewrite <field_name>" trigger.
  const rewriteMatch = lowerMessage.match(/rewrite (\S+)/);
  const rewriteFieldName = rewriteMatch?.[1] ?? null;

  // Parse field names from "[fields:title,body,oe_teaser]" tag.
  const fieldsMatch = message.match(/\[fields:([^\]]+)\]/);
  const shouldRegenerate = fieldsMatch !== null;
  const regenFieldNames = fieldsMatch ? fieldsMatch[1]!.split(",") : [];

  if (rewriteFieldName) {
    // --- Rewrite flow: replace a single field with fresh text ---

    const existingField = lastSentFields[rewriteFieldName];

    if (!existingField) {
      // Unknown field or no draft yet: send a conversational reply.
      const errId = randomUUID();
      const hint = Object.keys(lastSentFields).length > 0
        ? "Available fields: " + Object.keys(lastSentFields).join(", ")
        : 'Generate a draft first by saying "draft".';
      await streamText(
        res,
        errId,
        `I can't rewrite "${rewriteFieldName}". ${hint}`,
        isCancelled,
      );
    } else {
      const fieldLabel = (existingField.label as string) ?? rewriteFieldName;

      const rewriteIntroId = randomUUID();
      await streamText(
        res,
        rewriteIntroId,
        `Rewriting ${fieldLabel} with a fresh take.`,
        isCancelled,
      );
      if (cancelled) {
        res.end();
        return;
      }

      // Tool call: draft_content.
      const rewriteToolId = randomUUID();
      sendEvent(res, {
        type: "TOOL_CALL_START",
        toolCallId: rewriteToolId,
        toolCallName: "draft_content",
      });
      sendEvent(res, {
        type: "TOOL_CALL_ARGS",
        toolCallId: rewriteToolId,
        delta: JSON.stringify({ fields: [rewriteFieldName] }),
      });
      sendEvent(res, { type: "TOOL_CALL_END", toolCallId: rewriteToolId });

      await delay(800);
      if (cancelled) {
        res.end();
        return;
      }

      sendEvent(res, {
        type: "TOOL_CALL_RESULT",
        messageId: randomUUID(),
        toolCallId: rewriteToolId,
        content: `Field ${rewriteFieldName} rewritten.`,
      });

      // Generate fresh lorem ipsum for the field.
      const rewriteValue = generateRewriteValue(existingField);

      // Build updated fields preserving the original key order
      // so the rewritten field stays in its original position.
      const updatedFields: Record<string, Record<string, unknown>> = {};
      for (const key of Object.keys(lastSentFields)) {
        updatedFields[key] =
          key === rewriteFieldName ? rewriteValue : lastSentFields[key]!;
      }

      const rewriteCancelled = await streamFieldSnapshots(
        res,
        updatedFields,
        isCancelled,
      );
      if (rewriteCancelled) {
        res.end();
        return;
      }

      const confirmId = randomUUID();
      await streamText(
        res,
        confirmId,
        `Done! I've rewritten ${fieldLabel}.`,
        isCancelled,
      );
    }
  } else if (shouldRegenerate) {
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

    // Build updated fields preserving key order from the last
    // snapshot. Regenerated fields get fresh lorem ipsum text.
    const updatedFields: Record<string, Record<string, unknown>> = {};
    const baseline = Object.keys(lastSentFields).length > 0
      ? lastSentFields
      : DEMO_FIELDS;
    for (const key of Object.keys(baseline)) {
      if (regenFieldNames.includes(key) && baseline[key]) {
        updatedFields[key] = generateRewriteValue(baseline[key]!);
      } else {
        updatedFields[key] = baseline[key]!;
      }
    }

    // Stream the updated fields progressively. Only the
    // regenerated fields are new; the rest carry over unchanged.
    const regenCancelled = await streamFieldSnapshots(
      res,
      updatedFields,
      isCancelled,
    );
    if (regenCancelled) return res.end();

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

    // Step 1: stream the assistant's reasoning.
    const thinkingId = randomUUID();
    const thinkingText =
      "I'll generate content for the News fields: title, " +
      "introduction, body text, teaser, alternative title, " +
      "and publication date.";

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

    // Step 4: stream drafted fields progressively, field by field.
    const draftCancelled = await streamFieldSnapshots(
      res,
      DEMO_FIELDS,
      isCancelled,
    );
    if (draftCancelled) return res.end();

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
  // Clear tracked snapshot so the next draft streams from scratch.
  lastSentFields = {};
  res.json({ threadId: randomUUID() });
});
