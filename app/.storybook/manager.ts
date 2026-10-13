import { addons } from "storybook/manager-api";

/**
 * The story replicating the whole back-office workspace, which needs the
 * full window width to read as the screen it stands for.
 */
const FULL_WIDTH_STORY = "shell-full-app--full-app";

// Hide the story tree and the addon panel while that story is open. Story
// parameters no longer carry the manager layout, so the decision is made
// here, per story id.
addons.setConfig({
  layoutCustomisations: {
    showSidebar: (state, defaultValue) =>
      state.storyId === FULL_WIDTH_STORY ? false : defaultValue,
    showPanel: (state, defaultValue) =>
      state.storyId === FULL_WIDTH_STORY ? false : defaultValue,
  },
});
