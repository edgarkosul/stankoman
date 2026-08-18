import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Витрина (публичная часть) — @vite в resources/views/partials/head.blade.php
                'resources/css/app.css',
                'resources/js/app.js',
                // Тема админки Filament (подключается через ->viteTheme(...))
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
