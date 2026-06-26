/** Standalone configuration used by the Vite development entry point. */

import type { AppInitConfig } from "./config";

export const developmentConfig = {
  userId: "dev-editor",
  sessionId: "dev-session",
  pluginConfig: {
    drafting: {
      entityTypeId: "node",
      bundle: "oe_news",
      context: {
        tone: [
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
    },
  },
} satisfies AppInitConfig;
