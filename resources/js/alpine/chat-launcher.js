/**
 * Кнопка чата: раскрытие панели, сторож ответа при свёрнутой панели
 * и ленивая подгрузка маскота.
 *
 * Живёт здесь, а не в атрибуте x-data, ровно по той же причине, по какой
 * панель грузится лениво: лаунчер стоит на каждой странице витрины, и полсотни
 * строк логики в разметке — это полсотни строк, которые каждый посетитель
 * скачивает заново на каждой странице. В бандле они кэшируются один раз.
 *
 * Зачем сторож. Скрытая панель сервер не опрашивает вовсе (`wire:poll`
 * в display:none не тикает), поэтому ответ менеджера, пришедший на уже
 * открытой странице, посетитель не видел: бейдж считался только при
 * отрисовке. Теперь его считает и этот опрос — но лишь тогда, когда
 * сервер сказал, что есть чего ждать.
 *
 * @param {{open: boolean, unread: number, pollSeconds: number|null, url: string,
 *          stopsAfter: number, invite: string}} config
 */
export default (config) => {
    /*
     * Контроллер маскота держится в замыкании, а не в данных Alpine.
     *
     * Это не стилистика: Alpine рекурсивно оборачивает данные компонента
     * в реактивный Proxy. Внутри контроллера — состояние движка, которое
     * мутируется шестьдесят раз в секунду; протаскивать его через систему
     * эффектов незачем и небезопасно.
     */
    let mascot = null;

    /*
     * Второй робот — в подсказке. Монтируется не сразу, а когда подсказку
     * впервые показали: до этого она в display:none, и у коробки нулевой
     * размер, от которого движку нечего считать.
     */
    let bubbleMascot = null;

    /* Таймеры подсказки: показать через паузу и убрать, не дождавшись клика. */
    let inviteTimer = null;
    let hideTimer = null;

    /* Сессия помнит «уже звали», браузер — «просили не звать». */
    const GREETED_KEY = 'intertooler.chat.greeted';
    const DISMISSED_KEY = 'intertooler.chat.invite-dismissed';

    /* Отказ уважаем месяц: закрывший подсказку не хочет её и завтра. */
    const DISMISSED_DAYS = 30;

    /* Пауза до показа и время жизни подсказки, секунды. */
    const INVITE_DELAY = 4;
    const INVITE_LIFETIME = 12;

    /*
     * Пауза перед тем, как убрать подсказку, за которой ушёл курсор.
     *
     * Между роботом и подсказкой есть просвет, и курсор, идущий к тексту
     * или к крестику, обязан его пересечь. Без паузы подсказка гасла бы
     * ровно в этот момент — то есть каждый раз, когда её пытаются прочесть.
     */
    const HOVER_LEAVE_MS = 250;

    function store(area, key, value) {
        try {
            window[area].setItem(key, value);
        } catch (e) {
            // Приватное окно или запрет на хранилище — просто забудем.
        }
    }

    function read(area, key) {
        try {
            return window[area].getItem(key);
        } catch (e) {
            return null;
        }
    }

    return {
        open: config.open,
        unread: config.unread,
        pollSeconds: config.pollSeconds,
        elapsed: 0,
        timer: null,

        /* Подсказка у кнопки: видна ли и по какому поводу. */
        invite: false,
        inviteReason: null,

        init() {
            this.$watch('open', (open) => (open ? this.stopWatching() : this.resume()));
            this.$watch('unread', (unread) => this.onUnread(unread));

            /*
             * Кнопка «наверх» живёт в том же углу и на той же высоте, куда
             * встаёт подсказка. Прятать её на эти секунды честнее, чем
             * двигать: подсказка уйдёт сама, а прыгающая кнопка прокрутки
             * запомнится.
             */
            this.$watch('invite', (invite) => {
                this.$dispatch('chat-invite', invite);
                if (invite) this.mountBubbleMascot();
            });
            this.startWatching();
            this.loadMascot();

            /*
             * `$watch` на начальном значении не срабатывает, поэтому ответ,
             * посчитанный сервером ещё при отрисовке, надо разобрать руками —
             * иначе посетитель, вернувшийся на сайт, увидел бы бейдж без
             * единого слова о том, что случилось.
             */
            if (this.unread > 0) {
                this.onUnread(this.unread);
            } else {
                this.scheduleInvite();
            }
        },

        get inviteText() {
            return this.inviteReason === 'unread'
                ? 'Вам ответили — загляните в чат'
                : config.invite;
        },

        /*
         * Первый показ за посещение.
         *
         * Через паузу, а не сразу: подсказка, выскочившая вместе со
         * страницей, попадает в тот момент, когда человек ещё не понял,
         * куда пришёл, и закрывается не глядя.
         *
         * Один раз за сессию, а не на страницу: посетитель ходит по
         * каталогу десятками карточек, и подсказка на каждой из зова
         * превращается в помеху.
         */
        scheduleInvite() {
            if (this.open || config.open) return;
            if (read('sessionStorage', GREETED_KEY) === '1') return;

            const dismissed = Number(read('localStorage', DISMISSED_KEY) ?? 0);
            if (dismissed && Date.now() - dismissed < DISMISSED_DAYS * 86400000) return;

            inviteTimer = setTimeout(() => {
                inviteTimer = null;
                // За эти секунды могли открыть чат или получить ответ —
                // тогда зазывать уже некого и незачем.
                if (this.open || this.unread > 0) return;

                store('sessionStorage', GREETED_KEY, '1');
                this.inviteReason = 'greeting';
                this.invite = true;

                hideTimer = setTimeout(() => {
                    hideTimer = null;
                    if (this.inviteReason === 'greeting') this.invite = false;
                }, INVITE_LIFETIME * 1000);
            }, INVITE_DELAY * 1000);
        },

        /*
         * Пришёл ответ.
         *
         * Эта подсказка не считается приглашением: она не спрашивает
         * разрешения у хранилища и не уезжает по таймеру. Приглашение —
         * реклама, которую вежливо показать один раз; ответ менеджера —
         * уведомление, и прятать его через двенадцать секунд значит
         * повторить дефект, ради которого сторож и писался.
         */
        onUnread(unread) {
            mascot?.setAlert(unread > 0);

            if (unread > 0) {
                this.clearInviteTimers();
                this.inviteReason = 'unread';
                this.invite = true;

                return;
            }

            if (this.inviteReason === 'unread') this.invite = false;
        },

        /*
         * Подсказка по наведению.
         *
         * Автопоказ случается один раз за посещение и длится двенадцать
         * секунд — человек, подошедший к роботу позже, не должен гадать,
         * что это за фигура в углу. Наведение и есть прямой вопрос «что
         * это», отвечать на него нужно всегда.
         *
         * Поэтому наведение не спрашивает разрешения у хранилища: отказ
         * крестиком означает «не выскакивай сам», а не «не отвечай,
         * когда я спрашиваю».
         */
        hoverInvite(on) {
            // На открытой панели звать некуда. Уведомление об ответе курсору
            // не подчиняется: оно снимается чтением, а не движением мыши.
            if (this.open || this.inviteReason === 'unread') return;

            if (on) {
                // Гасим только автоснятие: отложенный автопоказ пусть живёт
                // своим чередом, наведение его не отменяет.
                if (hideTimer) clearTimeout(hideTimer);
                hideTimer = null;
                this.inviteReason = 'hover';
                this.invite = true;

                return;
            }

            hideTimer = setTimeout(() => {
                hideTimer = null;
                if (this.inviteReason === 'hover') this.invite = false;
            }, HOVER_LEAVE_MS);
        },

        dismissInvite() {
            this.clearInviteTimers();
            this.invite = false;
            // Отказ от приглашения помнится месяц; закрытое уведомление
            // об ответе — нет: следующий ответ снова достоин показа.
            if (this.inviteReason !== 'unread') {
                store('localStorage', DISMISSED_KEY, String(Date.now()));
            }
        },

        clearInviteTimers() {
            if (inviteTimer) clearTimeout(inviteTimer);
            if (hideTimer) clearTimeout(hideTimer);
            inviteTimer = null;
            hideTimer = null;
        },

        /*
         * Маскот приезжает, когда странице больше нечего делать.
         *
         * import() здесь динамический, поэтому движок с загрузчиком уезжают
         * в отдельный чанк: 25 КБ движка не должны ехать в app.js, который
         * качают все — в том числе те, кому виджет чата вовсе не показан.
         */
        async loadMascot() {
            const { shouldLoadMascot, attachMascot } = await import('../mascot/mascot-loader.js');

            if (!shouldLoadMascot()) return;

            const start = () => {
                attachMascot(this.$refs.mascot, {
                    /*
                     * Все амплитуды заданы здесь в конечных пикселях, а не
                     * получены пересчётом от размера сцены.
                     *
                     * Пересчёт даёт математически верное, но невидимое:
                     * парение, подобранное на сцене 460 px, на кнопке 72 px
                     * сжимается до одного пикселя, а подъём при наведении —
                     * до семи десятых. Маскот в углу страницы должен быть
                     * замечен боковым зрением, а не рассмотрен, поэтому
                     * движение здесь заведомо крупнее пропорционального.
                     *
                     * Держать его в этих рамках больше нечему: диска с
                     * обрезкой нет, робот волен выходить за свою коробку.
                     */
                    config: {
                        idle: {
                            floatAmplitude: 5.0, floatPeriod: 3.2,
                            floatAmplitude2: 1.8, floatPeriod2: 1.9,
                            driftAmplitude: 2.6, driftPeriod: 7.3,
                            // Наклон вчетверо заметнее авторского: силуэт
                            // с антенной качается, и это ловится краем глаза
                            // лучше, чем вертикальный ход.
                            rotateAmplitude: 3.2, rotatePeriod: 4.7,
                        },
                        // Заметил курсор — подобрался и подрос.
                        hover: { lift: -6, scale: 0.07, tiltToPointer: 2.4 },
                        // Фоновый зов: подскок раз в девять секунд.
                        ambient: { enabled: true, every: 9.0, strength: 0.8 },
                        nudge: { lift: -9 },
                        /*
                         * Дальность взгляда в пикселях экрана. Иначе она
                         * считается в полуширинах головы, и у кнопки шириной
                         * 72 px зрачки упирались бы в край, стоит курсору
                         * отойти на ширину пальца.
                         */
                        eyes: { reachPxX: 280, reachPxY: 200 },
                    },
                }).then((controller) => {
                    mascot = controller;
                    // Ответ мог прийти, пока робот ехал.
                    if (this.unread > 0) mascot?.setAlert(true);
                });
            };

            if (typeof requestIdleCallback === 'function') {
                requestIdleCallback(start, { timeout: 4000 });
            } else {
                setTimeout(start, 2000);
            }
        },

        /*
         * Робот в подсказке.
         *
         * Повадки урезаны намеренно. На кнопке маскот зовёт — там ему
         * положено быть заметным. В подсказке рядом с ним текст, который
         * надо прочитать, и второй пляшущий объект этому мешает: ни зова,
         * ни реакции на курсор, вдвое медленнее и вчетверо тише.
         */
        mountBubbleMascot() {
            if (bubbleMascot || !this.$refs.bubbleMascot) return;
            bubbleMascot = true; // занимаем место, пока идёт загрузка

            import('../mascot/mascot-loader.js').then(({ shouldLoadMascot, attachCalmMascot }) => {
                if (!shouldLoadMascot()) return;

                // Размер явно: коробку меряют до показа, и там ноль.
                return attachCalmMascot(this.$refs.bubbleMascot, 40)
                    .then((controller) => {
                        bubbleMascot = controller;
                    });
            });
        },

        /**
         * Развернуть панель.
         *
         * Событие `chat-opened` здесь обязательно, и вот почему. Свёрнутая
         * панель сервер не опрашивает вовсе, поэтому уже загруженный компонент
         * остаётся с лентой, собранной в тот момент, когда его последний раз
         * показывали. Посетитель жмёт на бейдж «вам ответили», открывает чат —
         * и видит переписку БЕЗ этого ответа: он приедет на первом тике
         * `wire:poll`, то есть через 4–10 секунд. Пауза заметная, а читается
         * ещё хуже, чем длится: бейдж был, ответа нет, значит соврали.
         *
         * Livewire слушает такие события на window, поэтому обычного alpine'ного
         * $dispatch достаточно. Панель ещё не загружена (первое открытие на
         * странице) — событие пропадёт, и это правильно: ленивая загрузка сама
         * соберёт ленту свежей.
         */
        show() {
            // Чат открыли — человека уже представили, звать больше некуда.
            this.clearInviteTimers();
            this.invite = false;
            store('sessionStorage', GREETED_KEY, '1');

            this.$dispatch('chat-opened');
            this.open = true;
        },

        startWatching() {
            if (this.timer || this.open || !this.pollSeconds || this.unread > 0) return;

            this.timer = setInterval(() => {
                this.elapsed += this.pollSeconds;
                this.check();
            }, this.pollSeconds * 1000);
        },

        stopWatching() {
            if (this.timer) clearInterval(this.timer);
            this.timer = null;
        },

        /**
         * Панель свернули. Переписку прочитали — но ждать ли дальше, знает
         * только сервер: разговор мог остаться у менеджера, и тогда следующий
         * его ответ снова придёт в закрытый чат. Спрашиваем сразу, не дожидаясь
         * первого тика.
         */
        async resume() {
            this.elapsed = 0;
            await this.check();
            this.startWatching();
        },

        async check() {
            // Вкладку оставили открытой надолго. Дальше молчим: ответ покажет
            // бейдж на следующей странице, его считает сервер при отрисовке.
            if (this.elapsed >= config.stopsAfter) {
                this.stopWatching();

                return;
            }

            try {
                const response = await fetch(config.url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) return;

                const state = await response.json();

                this.unread = state.unread ?? 0;
                this.pollSeconds = state.poll ?? null;

                // Ответ пришёл — бейдж стоит; либо сервер больше не считает
                // это ожиданием. И то и другое значит «спрашивать нечего».
                if (this.unread > 0 || !this.pollSeconds) this.stopWatching();
            } catch (e) {
                // Сеть моргнула — молча ждём следующего тика. Ошибка в консоли
                // посетителя ради счётчика непрочитанного не стоит ничего,
                // кроме испуга.
            }
        },
    };
};
