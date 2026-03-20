import { resolve } from "node:path";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react-swc";
import { defineConfig } from "vite";

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      "@": resolve(__dirname, "src"),
    },
  },
  // Replace process.env.NODE_ENV in dependencies that reference it.
  // Vite handles import.meta.env but not process.env in library mode.
  define: {
    "process.env.NODE_ENV": JSON.stringify("production"),
  },
  build: {
    // Output directly to the module root's dist/ directory so the
    // Drupal library (oe_ai_assistant.libraries.yml) can reference
    // the built assets without a copy step.
    outDir: resolve(__dirname, "../dist"),
    // Library mode: expose init() as the public API. The Drupal host
    // page loads the built JS and calls AiEditorialAssistant.init().
    lib: {
      entry: resolve(__dirname, "src/init.tsx"),
      name: "AiEditorialAssistant",
      fileName: "ai-editorial-assistant",
      formats: ["iife"],
    },
    // Extract CSS into a separate file so the Drupal module can load
    // it as a library asset. IIFE builds don't support inline CSS injection.
    cssCodeSplit: false,
    rollupOptions: {
      // No external deps -- everything is bundled into a single file
      // that the CMS loads via a <script> tag.
      output: {
        assetFileNames: "[name][extname]",
      },
    },
    // Generate a manifest so the Drupal module can discover the hashed
    // filenames for <script> and <link> injection.
    manifest: true,
  },
  server: {
    host: true,
    // Proxy API requests to the Express dev server.
    proxy: {
      "/api": {
        target: "http://localhost:5150",
        changeOrigin: true,
      },
    },
  },
});
