import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                entryFileNames: `assets/[name]-[hash]-v6.js`,
                chunkFileNames: `assets/[name]-[hash]-v6.js`,
                assetFileNames: `assets/[name]-[hash]-v6[extname]`,
                manualChunks(id) {
                    if (id.includes('node_modules/vue') || id.includes('node_modules/pinia')) {
                        return 'vendor-vue';
                    }
                    if (id.includes('node_modules/laravel-echo') || id.includes('node_modules/pusher-js')) {
                        return 'vendor-realtime';
                    }
                    if (id.includes('cameraHqPlayer.js') || id.includes('WebGLRenderer') || id.includes('decoder_worker')) {
                        return 'vendor-charts-player';
                    }
                }
            }
        }
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'https://camera-dev.8gategames.com',
        hmr: {
            host: 'camera-dev.8gategames.com',
            protocol: 'wss',
            clientPort: 443,
            path: '/@vite-hmr',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
