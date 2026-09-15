/**
 * Капча: загрузка виджета и добыча токена.
 *
 * Модуль прячет весь SmartCaptcha за одним обещанием: `captchaToken(config)`
 * отдаёт Promise со строкой токена. Пустая строка — это тоже ответ, а не
 * ошибка: что с ней делать, решает сервер по своей политике, а не браузер.
 *
 * Конфиг приходит с сервера целиком (CaptchaManager::frontendConfig) —
 * разметка про капчу ничего не решает и ничего не вычисляет.
 *
 * Ленивость обязательна. Скрипт SmartCaptcha весит 124 КБ, и грузить его на
 * каждой странице каталога ради проверки, которая случится в одном открытии
 * чата из ста, нельзя. Поэтому загрузка начинается при открытии формы,
 * а промис мемоизируется — скрипт подключается один раз на страницу.
 */

let loadPromise = null;
let widgetId = null;
let inFlight = null;
let abortTimer = null;

/** Сколько ждём токен после закрытия окна с заданием, прежде чем сдаться. */
const ABORT_GRACE_MS = 500;

/** Разово подключаем внешний скрипт. */
function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            loadPromise = null; // дать следующей попытке шанс
            reject(new Error(`captcha script failed: ${src}`));
        };
        document.head.appendChild(script);
    });
}

/**
 * Контейнер виджета живёт в body, а не в разметке формы.
 *
 * Контейнер обязателен даже в невидимом режиме, а панель чата грузится
 * лениво и перерисовывается Livewire на каждый вопрос: виджет внутри неё
 * пересоздавался бы вместе с разметкой. В body он не зависит ни от того,
 * ни от другого, поэтому и `wire:ignore` нигде не нужен.
 *
 * Он же один на страницу: второй виджет был бы второй загрузкой того же
 * скрипта.
 */
function host() {
    let el = document.getElementById('smartcaptcha-host');

    if (!el) {
        el = document.createElement('div');
        el.id = 'smartcaptcha-host';
        document.body.appendChild(el);
    }

    return el;
}

function loadSmartCaptcha(config) {
    if (loadPromise) return loadPromise;

    loadPromise = new Promise((resolve, reject) => {
        // Расширенный способ подключения — единственный, который умеет
        // невидимый режим: виджет создаётся не сам, а нашим колбэком.
        window.__captchaOnload = () => {
            widgetId = window.smartCaptcha.render(host(), {
                sitekey: config.siteKey,
                invisible: config.invisible,
                hideShield: config.hideShield,
                hl: config.language,
                test: config.test,
                callback: (token) => finish(token),
            });

            // Сеть отвалилась или скрипт сломался — отдаём пустой токен
            // и не держим посетителя на кнопке. Что с этим делать, решит
            // сервер: у него на такой случай своя политика.
            window.smartCaptcha.subscribe(widgetId, 'network-error', () => finish(''));
            window.smartCaptcha.subscribe(widgetId, 'javascript-error', () => finish(''));

            // Успех приходит и колбэком, и событием; finish() отработает
            // по первому и на второй уже не отреагирует.
            window.smartCaptcha.subscribe(widgetId, 'success', (token) => finish(token));

            /*
             * Посетитель закрыл головоломку крестиком — без этого кнопка
             * осталась бы в спиннере навсегда.
             *
             * Но окно закрывается и когда задание РЕШЕНО, а порядок событий
             * Яндекс не документирует: если challenge-hidden опережает
             * колбэк с токеном, отмена по нему выбросила бы честно решённую
             * капчу. Поэтому не отменяем сразу, а даём токену долететь.
             */
            window.smartCaptcha.subscribe(widgetId, 'challenge-hidden', () => {
                abortTimer = window.setTimeout(() => finish(''), ABORT_GRACE_MS);
            });

            window.smartCaptcha.subscribe(widgetId, 'token-expired', () => {
                window.smartCaptcha.reset(widgetId);
            });

            resolve();
        };

        const url = new URL(config.jsUrl);
        url.searchParams.set('render', 'onload');
        url.searchParams.set('onload', '__captchaOnload');

        loadScript(url.toString()).catch(reject);
    });

    return loadPromise;
}

/** Завершить текущую проверку — чем бы она ни кончилась. */
function finish(token) {
    window.clearTimeout(abortTimer);
    abortTimer = null;

    if (!inFlight) return;

    const resolve = inFlight;
    inFlight = null;
    resolve(String(token || ''));
}

/** Начать загрузку заранее — при открытии формы, а не при отправке. */
export function captchaPreload(config) {
    if (!config?.enabled) return;

    loadSmartCaptcha(config).catch(() => {
        // Молча: неудачная предзагрузка не должна ломать открытие формы,
        // а на отправке попытка повторится.
    });
}

/** Токен для отправки формы. Пустая строка — это тоже ответ. */
export async function captchaToken(config) {
    if (!config?.enabled) return '';

    try {
        await loadSmartCaptcha(config);

        // Проверка уже идёт — посетитель жмёт кнопку второй раз. Отдаём ему
        // тот же результат вместо второго виджета поверх первого.
        if (inFlight) {
            return await new Promise((resolve) => {
                const previous = inFlight;
                inFlight = (token) => { previous(token); resolve(token); };
            });
        }

        return await new Promise((resolve) => {
            inFlight = resolve;

            /*
             * Сброс перед каждым запуском — не перестраховка.
             *
             * Токен одноразовый и живёт пять минут. Если сервер завернёт
             * отправку по другой причине (кулдаун, слишком короткий вопрос),
             * посетитель исправит и нажмёт снова — и уйдёт уже потраченный
             * токен, на который сервис ответит «Invalid or expired Token».
             * Отправка перестала бы работать совсем, причём необъяснимо.
             */
            window.clearTimeout(abortTimer);
            abortTimer = null;

            window.smartCaptcha.reset(widgetId);
            window.smartCaptcha.execute(widgetId);
        });
    } catch {
        return '';
    }
}
