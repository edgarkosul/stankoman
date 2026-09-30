/*
 * Ленивая подгрузка маскота.
 *
 * Артворк весит 70 КБ — это немного для одной картинки, но лаунчер стоит
 * на 24 тысячах страниц, и платить за него при первой отрисовке незачем:
 * кнопка чата нужна не в первую секунду. Поэтому на кнопке сразу рисуется
 * лёгкая иконка-облачко, а робот приезжает, когда страница успокоилась,
 * и подменяет её кроссфейдом.
 *
 * Файл тянется через Vite (`?url`), значит лежит в /build/assets с хэшем
 * в имени и `immutable`-кэшем: скачается один раз на весь обход каталога.
 *
 * Этот модуль и сам движок попадают в отдельный чанк — их подключают
 * динамическим import() из chat-launcher.js, чтобы 25 КБ движка не ехали
 * в app.js, который качают все.
 */

import mascotUrl from './robot-head.svg?url';
import { mountMascot } from './robot-mascot.js';

/* Вкладка, на которой загрузка уже падала: не долбим сеть на каждой карточке. */
const FAILED_KEY = 'intertooler.mascot.failed';

/*
 * Разметка артворка, скачанная один раз на страницу.
 *
 * Роботов на странице двое — на кнопке и в подсказке рядом с ней, — и
 * второму незачем даже трогать кэш браузера: текст уже здесь. Кэшируется
 * промис, а не результат: два монтирования могут начаться в одном кадре.
 */
let sourcePromise = null;

/* Счётчик экземпляров: из него получается уникальный префикс id. */
let mounted = 0;

/*
 * Повадки для мелких мест — подсказки у кнопки и шапки панели.
 *
 * На кнопке маскот зовёт, и там ему положено быть заметным. Здесь он стоит
 * рядом с текстом, который надо прочитать, и второй пляшущий объект этому
 * мешает: ни фонового зова, ни реакции на курсор, вдвое медленнее
 * и вчетверо тише. Взгляд остаётся — он и делает лицо живым, — но мягкий
 * и с большой дальностью, то есть без рывков.
 */
const CALM = {
    idle: {
        floatAmplitude: 1.6, floatPeriod: 4.6,
        floatAmplitude2: 0.5, floatPeriod2: 2.9,
        driftAmplitude: 0.5, driftPeriod: 11.0,
        rotateAmplitude: 1.1, rotatePeriod: 7.4,
    },
    ambient: { enabled: false },
    hover: { enabled: false },
    eyes: { reachPxX: 900, reachPxY: 700, stiffness: 60, damping: 16 },
};

function remember(key) {
    try {
        sessionStorage.setItem(key, '1');
    } catch (e) {
        // Приватное окно или запрет на хранилище — просто забудем.
    }
}

function remembered(key) {
    try {
        return sessionStorage.getItem(key) === '1';
    } catch (e) {
        return false;
    }
}

/**
 * Стоит ли вообще тратить 70 КБ на этого посетителя.
 *
 * `prefers-reduced-motion` здесь НЕ проверяется намеренно: маскот — это лицо
 * консультанта, а не анимация. Человеку, попросившему меньше движения, робот
 * покажется неподвижным (движок сам не запустит цикл), но покажется.
 */
export function shouldLoadMascot() {
    if (remembered(FAILED_KEY)) return false;

    const net = navigator.connection;
    if (!net) return true;

    // Явная просьба экономить трафик — уважаем.
    if (net.saveData === true) return false;

    // На 2G семьдесят килобайт это секунды ожидания ради украшения.
    return !/(^|-)2g$/.test(net.effectiveType ?? '');
}

/**
 * Скачать артворк, вставить в host и оживить.
 *
 * @param {HTMLElement} host контейнер фиксированного размера; внутри него
 *        уже лежит плейсхолдер, который будет снят после появления робота
 * @param {object} [options] пробрасывается в mountMascot
 * @returns {Promise<object|null>} контроллер маскота либо null, если не вышло
 */
export async function attachMascot(host, options = {}) {
    try {
        if (!sourcePromise) {
            sourcePromise = fetch(mascotUrl, { credentials: 'omit' }).then((response) => {
                if (!response.ok) throw new Error('HTTP ' + response.status);

                return response.text();
            });
        }

        const source = await sourcePromise;

        /*
         * Идентификаторы переименовываются на каждый экземпляр.
         *
         * Внутри артворка есть clip-path: url(#…), а такая ссылка ищется
         * по ВСЕМУ документу, не внутри своего svg. Два робота с одинаковыми
         * id — и второй начал бы обрезать себя по клипам первого. Сейчас
         * геометрия у них совпадает и это сошло бы с рук, но держаться
         * на таком совпадении не стоит.
         */
        const prefix = 'km' + (++mounted) + '-';
        const text = source
            .replaceAll('id="km-', 'id="' + prefix)
            .replaceAll('url(#km-', 'url(#' + prefix);

        // DOMParser, а не innerHTML: файл свой, но привычка совать разметку
        // строкой в innerHTML однажды встретится с чужой строкой.
        const parsed = new DOMParser().parseFromString(text, 'image/svg+xml');
        const svg = parsed.documentElement;
        if (!svg || svg.nodeName.toLowerCase() !== 'svg') {
            throw new Error('это не svg');
        }

        // Доступное имя даёт кнопка. Второй голос из картинки — лишний.
        svg.removeAttribute('role');
        svg.removeAttribute('aria-label');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.classList.add('chat-mascot');

        const node = document.importNode(svg, true);
        node.style.opacity = '0';
        host.appendChild(node);

        const controller = mountMascot(node, {
            frame: host,
            size: host.getBoundingClientRect().width || undefined,
            fixed: true,
            idPrefix: prefix,
            ...options,
        });

        // Кадр на укладку, потом кроссфейд: резкая подмена картинки боковым
        // зрением читается как глюк, а не как появление.
        requestAnimationFrame(() => {
            node.style.opacity = '';
            const placeholder = host.querySelector('[data-mascot-placeholder]');
            if (placeholder) {
                placeholder.style.opacity = '0';
                setTimeout(() => placeholder.remove(), 250);
            }
        });

        return controller;
    } catch (e) {
        // Сеть моргнула или файл не тот — молча остаёмся с облачком.
        // Кнопка чата от маскота не зависит и работает как раньше.
        remember(FAILED_KEY);

        return null;
    }
}

/**
 * Спокойный маскот в маленькой коробке.
 *
 * Размер передаётся явно, а не меряется: в обоих местах, где это нужно,
 * коробка на момент монтирования скрыта и меряется в ноль.
 *
 * @param {HTMLElement} host
 * @param {number} [size] сторона коробки в CSS-пикселях
 * @returns {Promise<object|null>}
 */
export function attachCalmMascot(host, size = 40) {
    return attachMascot(host, { size, config: CALM });
}
