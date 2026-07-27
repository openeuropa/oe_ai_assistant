/** Standalone configuration used by the Vite development entry point. */

import type { AppInitConfig } from "./config";

export const developmentConfig = {
  userId: "dev-editor",
  sessionId: "dev-session",
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
        options: [
          {
            id: "eu-ai-act-brief",
            title: "EU AI Act briefing note.pdf",
            meta: "PDF - 240 KB",
          },
          {
            id: "stakeholder-comments",
            title: "Stakeholder comments.docx",
            meta: "DOCX - 96 KB",
          },
        ],
      },
    },
  },
} satisfies AppInitConfig;
