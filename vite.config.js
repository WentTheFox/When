import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        vue(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/app.ts',
            ],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        // No explicit https/cors here — laravel-vite-plugin reads
        // VITE_DEV_SERVER_KEY/VITE_DEV_SERVER_CERT from .env itself and
        // configures the dev server's https+host+hmr to match APP_URL's
        // host, and its own default `server.cors` already allowlists
        // APP_URL's origin. That only works because APP_URL matches how
        // the app is actually served (https://when.went.lc) — nginx here
        // listens on both 80 and 443, but only 443 is actually browsed to.
    },
});
