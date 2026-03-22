import { sveltekit } from '@sveltejs/kit/vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [tailwindcss(), sveltekit()],
    server: {
        proxy: {
            // Proxy API requests to the FrankenPHP backend container.
            // In production, Caddy serves both the static frontend and the
            // PHP API under a single domain — no proxy needed there.
            '/api': {
                target: process.env['VITE_API_PROXY_TARGET'] ?? 'http://php:8080',
                changeOrigin: true,
                secure: false, // Accept self-signed certs (Caddy dev TLS)
            },
            '/.well-known/mercure': {
                target: process.env['VITE_API_PROXY_TARGET'] ?? 'http://php:8080',
                changeOrigin: true,
                secure: false,
                ws: true,
            },
        },
    },
});
