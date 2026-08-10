import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['resources/js/tests/**/*.test.js'],
        globals: true,
    },
});
