/// <reference types="vite/client" />

/**
 * Augment Vite's ImportMetaEnv with our custom env variables.
 * Vite replaces these at build time, enabling tree-shaking of
 * conditional code branches.
 */
interface ImportMetaEnv {
  /** Set to "true" to register dev-only test plugins (echo, notes). */
  readonly VITE_DEV_PLUGINS: string;
}
