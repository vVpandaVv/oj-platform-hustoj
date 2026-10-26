import path from "node:path";

import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

function platePreviewAiFallback() {
  return {
    name: "contents-editor-plate-preview-ai-fallback",
    configureServer(server) {
      server.middlewares.use("/api/ai/copilot", (_req, res) => {
        res.statusCode = 200;
        res.setHeader("Content-Type", "text/plain; charset=utf-8");
        res.end("0");
      });
    },
  };
}

export default defineConfig({
  base: "./",
  plugins: [react(), tailwindcss(), platePreviewAiFallback()],
  optimizeDeps: {
    exclude: [
      "@excalidraw/excalidraw",
      "png-chunk-text",
      "png-chunks-encode",
      "png-chunks-extract",
      "@braintree/sanitize-url",
      "lodash.throttle",
      "lodash.debounce",
      "use-sync-external-store/shim/with-selector",
      "fuzzy",
    ],
    include: ["es6-promise-pool"],
  },
  resolve: {
    alias: [
      {
        find: /^es6-promise-pool$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/es6-promise-pool.ts"),
      },
      {
        find: /^@excalidraw\/excalidraw$/,
        replacement: path.resolve(process.cwd(), "node_modules/@excalidraw/excalidraw/dist/prod/index.js"),
      },
      {
        find: /^png-chunk-text$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/png-chunk-text.ts"),
      },
      {
        find: /^png-chunks-encode$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/png-chunks-encode.ts"),
      },
      {
        find: /^png-chunks-extract$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/png-chunks-extract.ts"),
      },
      {
        find: /^@braintree\/sanitize-url$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/sanitize-url.ts"),
      },
      {
        find: /^lodash\.throttle$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/lodash-throttle.ts"),
      },
      {
        find: /^lodash\.debounce$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/lodash-debounce.ts"),
      },
      {
        find: /^use-sync-external-store\/shim\/with-selector(?:\.js)?$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/use-sync-external-store-with-selector.ts"),
      },
      {
        find: /^fuzzy(?:\/lib\/fuzzy\.js)?$/,
        replacement: path.resolve(process.cwd(), "assets/src/admin/shims/fuzzy.ts"),
      },
      {
        find: "@",
        replacement: path.resolve(process.cwd(), "assets/src/admin"),
      },
      { find: "buffer", replacement: "buffer" },
      { find: "events", replacement: "events" },
      { find: "path", replacement: "path-browserify" },
    ],
  },
  build: {
    outDir: "assets/editor",
    emptyOutDir: true,
    rollupOptions: {
      input: {
        admin: "assets/src/admin/index.tsx",
        public: "assets/src/public/index.ts",
      },
      output: {
        entryFileNames: "[name].js",
        chunkFileNames: "chunks/[name]-[hash].js",
        assetFileNames: (assetInfo) => {
          const name = assetInfo.names?.[0] ?? assetInfo.name ?? "asset";

          return name.endsWith(".css")
            ? "[name][extname]"
            : "assets/[name]-[hash][extname]";
        },
      },
    },
  },
});
