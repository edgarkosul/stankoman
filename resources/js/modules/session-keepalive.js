/**
 * Продление сессии, пока вкладка открыта.
 *
 * Сессия живёт SESSION_LIFETIME минут (сейчас 120), а страницы магазина
 * держат открытыми часами — карточку товара оставляют «на подумать».
 * Умершая сессия означает 419 на первое же действие: Livewire показывает
 * английское «This page has expired», и покупатель видит его в ответ
 * на нажатие кнопки чата.
 *
 * Два триггера, и второй важнее первого.
 *
 * Таймер раз в десять минут держит сессию живой у активной вкладки.
 * Но фоновую вкладку браузер сначала притормаживает, а потом и вовсе
 * замораживает — таймеры в ней не выполняются вообще, и после нескольких
 * часов в фоне сессия всё равно умирает.
 *
 * Поэтому главный триггер — возвращение к вкладке: перед тем как человек
 * успеет что-то нажать, мы продлеваем сессию и, если она уже умерла,
 * забираем токен новой. Без обновления токена пинг бесполезен: новая
 * сессия — новый токен, а страница осталась со старым.
 */
const KEEPALIVE_INTERVAL_MS = 10 * 60 * 1000;

/** Не чаще раза в минуту: возврат к вкладке бывает частым. */
const MIN_GAP_MS = 60 * 1000;

let lastPingAt = 0;

export function renewSession() {
    const now = Date.now();

    if (now - lastPingAt < MIN_GAP_MS) {
        return Promise.resolve(false);
    }

    lastPingAt = now;

    return fetch('/session/keepalive', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
            const token = data?.token;
            const meta = document.querySelector('meta[name="csrf-token"]');

            if (!token || !meta) {
                return false;
            }

            // Livewire читает токен из этого тега перед каждым запросом,
            // поэтому подмена здесь чинит и уже загруженные компоненты.
            meta.setAttribute('content', token);

            return true;
        })
        .catch(() => false);
}

/** Пинг вне очереди — например, после 419. */
export function forceRenewSession() {
    lastPingAt = 0;

    return renewSession();
}

function start() {
    setInterval(renewSession, KEEPALIVE_INTERVAL_MS);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            renewSession();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
