import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    // --- Порты дев-стенда (единая схема, см. ~/.config/devbox/PORTS.md) ------
    // Сайт отдаёт nginx на 127.0.0.1:8103, Vite слушает 127.0.0.1:5103:
    // последние две цифры у пары всегда совпадают. Оба порта проброшены с
    // Windows ssh-туннелем, поэтому Vite слушает строго IPv4-loopback —
    // наружу порт отдаёт туннель, а не Vite (у машины публичный IP).
    // Без явного host Vite сел бы на [::1], недостижимый со стороны браузера.
    const env = loadEnv(mode, process.cwd(), 'VITE_');
    const appPort = Number(env.VITE_APP_PORT || 8103);
    const vitePort = Number(env.VITE_PORT || 5103);
    // VITE_DEV_HOST — LAN-адрес Windows-машины, если дев смотрят с телефона
    // через `ssh -L 0.0.0.0:...`. Пусто — обычная работа через туннель.
    const devHost = env.VITE_DEV_HOST || '127.0.0.1';
    const viaLan = devHost !== '127.0.0.1';

    return {
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
        server: {
            host: '127.0.0.1',
            port: vitePort,
            // Порт занят — падаем с внятной ошибкой, а не уползаем на соседний,
            // оставив в public/hot неверный адрес.
            strictPort: true,
            // Попадает в public/hot, откуда Laravel берёт URL ассетов.
            origin: `http://${devHost}:${vitePort}`,
            cors: {
                origin: [
                    /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/,
                    // Страница, открытая на телефоне, — чужой origin для Vite.
                    ...(viaLan ? [`http://${devHost}:${appPort}`] : []),
                ],
            },
            // Иначе клиент HMR полез бы на ws://127.0.0.1:<vite>, т.е. в сам телефон.
            ...(viaLan ? { hmr: { host: devHost } } : {}),
        },
    };
});
