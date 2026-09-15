import { forceRenewSession } from './session-keepalive';

/**
 * 419 без английского окна посреди магазина.
 *
 * По умолчанию Livewire на истёкшую сессию показывает браузерное
 * `confirm('This page has expired…')` и перезагружает страницу. Покупатель
 * видит это в ответ на нажатие кнопки чата — то есть в самый неудачный
 * момент, да ещё по-английски.
 *
 * Прежняя версия сторожа слушала `respond` и перезагружала страницу сама,
 * но не звала `preventDefault`, поэтому окно всё равно успевало появиться.
 * Теперь мы перехватываем ошибку, гасим стандартное поведение и молча
 * продлеваем сессию, забрав новый токен. Открытые компоненты после этого
 * чинятся сами: `wire:poll` в чате на следующем тике уже проходит.
 *
 * Перезагрузка осталась запасным выходом — на случай, когда обновление
 * токена не помогло: два 419 подряд означают, что дело не в сессии.
 */
let recoveringSince = 0;

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status !== 419) {
                return;
            }

            preventDefault();

            const now = Date.now();

            // Второй 419 за полминуты — обновление токена не сработало.
            if (now - recoveringSince < 30_000) {
                window.location.reload();

                return;
            }

            recoveringSince = now;

            forceRenewSession().then((renewed) => {
                if (!renewed) {
                    window.location.reload();

                    return;
                }

                // Действие посетителя не выполнилось: набранное осталось
                // в поле, но нажать надо ещё раз. Чат скажет об этом сам.
                window.dispatchEvent(new CustomEvent('session-renewed'));
            });
        });
    });
});
