import type { Preview } from "@storybook/react-vite";
import { setConfig } from "../src/config";
import { developmentConfig } from "../src/development-config";
import "../src/index.css";
import "./preview.css";

/**
 * App config for stories, so hooks reading getConfig() (tone, template,
 * ...) have the standalone development config available. The live preview
 * URL is overridden because Storybook has no mock API server: the iframe
 * loads a public page instead of a rendered draft. Stories that set their
 * own config spread this one, keeping the override.
 */
export const storybookConfig = {
  ...developmentConfig,
  pluginConfig: {
    ...developmentConfig.pluginConfig,
    drafting: {
      ...developmentConfig.pluginConfig.drafting,
      preview: {
        url: "https://example.com/?session={sessionId}&version={versionId}",
      },
    },
  },
};

setConfig(storybookConfig);

const preview: Preview = {
  parameters: {
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },
    a11y: {
      test: "todo",
    },
  },
};

export default preview;
