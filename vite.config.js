import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/sass/app.scss', 'resources/js/app.js'],
            refresh: true,
            buildDirectory: 'build',
            assetFileNames: 'assets/[name]-[hash][ext]',
        }),
        vue(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                /*
                | Echo y Pusher van en su propio chunk: el websocket se
                | inicializa al arrancar, asi que separarlos no reduce la
                | descarga, pero evita que un cambio en el codigo de la app
                | invalide la cache de la libreria que nunca cambia.
                */
                manualChunks: {
                    echo: ['laravel-echo', 'pusher-js'],
                },
            },
        },
    },
    base: '/build/',
    server: {
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            host: process.env.HMR_HOST || 'localhost',
            protocol: process.env.HMR_PROTOCOL || 'ws',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});