import type { Preview } from "@storybook/react-vite";
import { setConfig } from "../src/config";
import { developmentConfig } from "../src/development-config";
import "../src/index.css";
import "./preview.css";

// Initialize the app config so stories whose hooks read getConfig()
// (tone, template, ...) have the standalone development config available.
// The live preview URL is overridden because Storybook has no mock API
// server: the iframe loads a public placeholder page instead.
setConfig({
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
});

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
