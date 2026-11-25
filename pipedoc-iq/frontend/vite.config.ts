import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
  plugins: [react()],
  define: { 'process.env.NODE_ENV': '"production"' },
  build: {
    outDir: '../pipedoc-iq/assets/app', emptyOutDir: true, cssCodeSplit: false, sourcemap: false,
    lib: { entry: 'src/main.tsx', name: 'PipeDocIQ', formats: ['iife'], fileName: () => 'app.js' },
    rollupOptions: { output: { assetFileNames: 'app.[ext]' } },
  },
});
