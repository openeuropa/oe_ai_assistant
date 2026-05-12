/**
 * Development entry point.
 *
 * Calls init() with sensible defaults for standalone development.
 * In production the host page (Drupal) calls init() directly via
 * the global AiEditorialAssistant.init() -- this file is not used.
 */

import { init } from "./init";
import "./index.css";

init("#root", {
  userId: "dev-editor",
  pluginConfig: {
    drafting: {
      entityTypeId: "node",
      bundle: "oe_news",
    },
  },
});
