import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['resources/js/**/__tests__/**/*.test.ts'],
    environment: 'node', // Node 19+ exposes WebCrypto (crypto.subtle) globally.
  },
});
