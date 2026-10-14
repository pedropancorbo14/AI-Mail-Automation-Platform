import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// Panel de administración (Fase 10): SPA de Vue servida por Laravel en "/".
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/panel/main.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js/panel', import.meta.url)),
        },
    },
    server: {
        // Servicio "vite" de Docker: escucha en el contenedor y el navegador entra por localhost.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        hmr: { host: 'localhost' },
        cors: true,
        watch: {
            usePolling: process.env.VITE_POLLING === 'true',
            interval: 300,
            ignored: ['**/storage/**', '**/vendor/**'],
        },
    },
});
