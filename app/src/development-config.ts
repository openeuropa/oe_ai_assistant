/** Standalone configuration used by the Vite development entry point. */

import type { AppInitConfig } from "./config";

export const developmentConfig = {
  userId: "dev-editor",
  userName: "Dev Editor",
  sessionId: "dev-session",
  sessionTitle: "Content creation: Dev editorial session",
  exitUrl: "/",
  disclaimer: "AI assistant can make mistakes. Please double-check responses.",
  pluginConfig: {
    drafting: {
      entityTypeId: "node",
      bundle: "oe_news",
      tone: {
        enabled: true,
        options: [
          {
            id: "clear-professional",
            label: "Clear and professional",
            description: "Be direct, neutral, and easy to scan.",
          },
          {
            id: "formal",
            label: "Formal",
            description: "Use an institutional, measured voice.",
          },
        ],
      },
      templates: {
        enabled: true,
        options: [
          {
            id: "news-article",
            label: "News article",
            description:
              "Structured article with headline, summary, body, and related links.",
          },
          {
            id: "press-release",
            label: "Press release",
            description:
              "Announcement-focused structure with key messages and media angle.",
          },
          {
            id: "policy-brief",
            label: "Policy brief",
            description:
              "Short explanatory format focused on context, impact, and next steps.",
          },
        ],
      },
      documents: {
        enabled: true,
        // Ids are the server-assigned document UUIDs.
        options: [
          {
            id: "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
            title: "EU AI Act briefing note.pdf",
            meta: { type: "pdf", size: 245760 },
            summary:
              "Summarises the main obligations, implementation timeline, and editorial framing for the AI Act announcement.",
          },
          {
            id: "c9bf9e57-1685-4c89-bafb-ff5af830be8a",
            title: "Stakeholder comments.docx",
            meta: { type: "docx", size: 98304 },
            extractionStatus: "processing",
          },
        ],
      },
    },
  },
} satisfies AppInitConfig;
