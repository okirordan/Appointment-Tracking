import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    test: {
        environment: 'jsdom',
        include: ['tests/frontend/**/*.test.tsx'],
        setupFiles: ['tests/frontend/setup.ts'],
    },
});
