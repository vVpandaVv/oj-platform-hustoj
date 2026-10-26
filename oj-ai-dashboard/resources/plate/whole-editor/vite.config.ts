import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src')
    }
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env': JSON.stringify({ NODE_ENV: 'production' })
  },
  build: {
    outDir: process.env.HWS_PLATE_BUILDER_OUT_DIR || resolve(__dirname, '../../assets/builder'),
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve(__dirname, 'src/main.tsx'),
      name: 'OjAiDashboardWorksheetPlateTemplateBuilder',
      formats: ['iife'],
      fileName: () => 'plate-template-builder.js'
    },
    rollupOptions: {
      output: {
        assetFileNames: 'plate-template-builder.css',
        inlineDynamicImports: true
      }
    }
  }
});

