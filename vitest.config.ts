import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/panel', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/panel/**/*.test.ts'],
    },
});
