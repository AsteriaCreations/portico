import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The admin panel's theme, plus the kiosk tablet's scanner (the one
            // screen outside the panel). Filament ships its own font, so there's
            // no separate site CSS.
            input: ['resources/css/filament/admin/theme.css', 'resources/js/kiosk.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
