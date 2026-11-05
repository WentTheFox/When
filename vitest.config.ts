import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  // Lets tests import .vue components (server-rendered; there is no DOM environment).
  plugins: [vue()],
  test: {
    include: ['resources/js/**/__tests__/**/*.test.ts'],
    environment: 'node', // Node 19+ exposes WebCrypto (crypto.subtle) globally.
  },
});
