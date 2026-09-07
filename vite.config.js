import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    // Дев-сервер слушаем строго по IPv4: проект открывается с Windows
    // через SSH-туннель, а он пробрасывает 127.0.0.1. Дефолтный
    // localhost Node резолвит в ::1, и ассеты становятся недоступны.
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
    },
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
