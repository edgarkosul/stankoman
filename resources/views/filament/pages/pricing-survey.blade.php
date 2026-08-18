<x-filament-panels::page>
    @php
        $answeredOnServer = filled($saved);
    @endphp

    <style>
        .ps {
            --ps-surface: #ffffff;
            --ps-surface-2: #f6f9f9;
            --ps-ink: #111d21;
            --ps-ink-2: #4b5c63;
            --ps-ink-3: #7b8b92;
            --ps-line: #d7dfe1;
            --ps-line-strong: #bdc9cd;
            --ps-accent: #0b5d66;
            --ps-accent-ink: #ffffff;
            --ps-accent-soft: #e3eff0;
            --ps-accent-line: #7fb4ba;
            --ps-note-bg: #fbf1e0;
            --ps-note-ink: #78490c;
            --ps-note-line: #e7d4b2;
            --ps-mono: "IBM Plex Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;

            display: flex;
            flex-direction: column;
            gap: 18px;
            max-width: 46rem;
            color: var(--ps-ink);
            font-size: 16px;
            line-height: 1.55;
        }

        .dark .ps {
            --ps-surface: #141e22;
            --ps-surface-2: #1a262a;
            --ps-ink: #e6edee;
            --ps-ink-2: #a5b6bb;
            --ps-ink-3: #7a9096;
            --ps-line: #253439;
            --ps-line-strong: #35494f;
            --ps-accent: #58c4ce;
            --ps-accent-ink: #06272c;
            --ps-accent-soft: #10333a;
            --ps-accent-line: #2f6e78;
            --ps-note-bg: #2a2113;
            --ps-note-ink: #e9c68c;
            --ps-note-line: #4a3a1d;
        }

        .ps [x-cloak] { display: none !important; }

        .ps-bar { display: flex; flex-direction: column; gap: 8px; }

        .ps-bar-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            font-family: var(--ps-mono);
            font-size: 11.5px;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--ps-ink-3);
        }

        .ps-track { height: 3px; background: var(--ps-line); border-radius: 2px; overflow: hidden; }
        .ps-track-fill { height: 100%; background: var(--ps-accent); border-radius: 2px; transition: width .35s cubic-bezier(.4, 0, .2, 1); }

        .ps-screen { display: flex; flex-direction: column; gap: 16px; }

        .ps-eyebrow {
            font-family: var(--ps-mono);
            font-size: 12px;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--ps-accent);
            margin: 0;
        }

        .ps-h1 { font-size: 28px; line-height: 1.2; font-weight: 700; letter-spacing: -.015em; margin: 0; text-wrap: balance; }
        .ps-h2 { font-size: 22px; line-height: 1.28; font-weight: 600; letter-spacing: -.012em; margin: 0; text-wrap: balance; }
        .ps-lede { color: var(--ps-ink-2); margin: 0; }

        .ps-note {
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 13px 15px;
            background: var(--ps-note-bg);
            border: 1px solid var(--ps-note-line);
            border-radius: 10px;
            color: var(--ps-note-ink);
            font-size: 15px;
        }

        .ps-note-label { font-family: var(--ps-mono); font-size: 11px; letter-spacing: .1em; text-transform: uppercase; opacity: .85; }

        .ps-options { display: flex; flex-direction: column; gap: 10px; border: 0; padding: 0; margin: 0; }

        .ps-option {
            position: relative;
            display: grid;
            grid-template-columns: 26px 1fr;
            gap: 12px;
            padding: 15px 16px;
            background: var(--ps-surface);
            border: 1px solid var(--ps-line);
            border-radius: 10px;
            cursor: pointer;
            transition: border-color .15s, background .15s;
        }

        .ps-option:hover { border-color: var(--ps-line-strong); }
        .ps-option input { position: absolute; opacity: 0; pointer-events: none; }

        .ps-mark {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1.5px solid var(--ps-line-strong);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--ps-mono);
            font-size: 13px;
            color: var(--ps-ink-3);
            background: var(--ps-surface-2);
            transition: background .15s, color .15s, border-color .15s;
        }

        .ps-option-body { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .ps-option-title { font-weight: 600; font-size: 17px; line-height: 1.35; }
        .ps-option-desc { color: var(--ps-ink-2); font-size: 15px; }

        .ps-option-effect {
            font-size: 14.5px;
            color: var(--ps-ink-2);
            padding-left: 11px;
            border-left: 2px solid var(--ps-line-strong);
        }

        .ps-tag {
            align-self: flex-start;
            font-family: var(--ps-mono);
            font-size: 10.5px;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 3px 7px;
            border-radius: 4px;
            background: var(--ps-accent-soft);
            color: var(--ps-accent);
            border: 1px solid var(--ps-accent-line);
        }

        .ps-option:has(input:checked) { border-color: var(--ps-accent); background: var(--ps-accent-soft); }
        .ps-option:has(input:checked) .ps-mark { background: var(--ps-accent); border-color: var(--ps-accent); color: var(--ps-accent-ink); }
        .ps-option:has(input:focus-visible) { outline: 2px solid var(--ps-accent); outline-offset: 2px; }

        .ps-calc {
            font-family: var(--ps-mono);
            font-size: 13.5px;
            font-variant-numeric: tabular-nums;
            color: var(--ps-ink-2);
            background: var(--ps-surface-2);
            border: 1px dashed var(--ps-line-strong);
            border-radius: 8px;
            padding: 9px 12px;
            overflow-x: auto;
            white-space: nowrap;
        }

        .ps-calc b, .ps-option-effect b { color: var(--ps-ink); font-weight: 600; }

        .ps-nav { display: flex; gap: 10px; align-items: center; padding-top: 4px; }

        .ps-btn {
            font: inherit;
            font-size: 15.5px;
            font-weight: 600;
            border-radius: 8px;
            border: 1px solid transparent;
            padding: 11px 20px;
            cursor: pointer;
            transition: background .15s, border-color .15s, opacity .15s;
        }

        .ps-btn:focus-visible { outline: 2px solid var(--ps-accent); outline-offset: 2px; }
        .ps-btn-primary { background: var(--ps-accent); color: var(--ps-accent-ink); flex: 1; }
        .ps-btn-primary:disabled { opacity: .4; cursor: not-allowed; }
        .ps-btn-ghost { background: transparent; color: var(--ps-ink-2); border-color: var(--ps-line-strong); }
        .ps-btn-ghost:hover { color: var(--ps-ink); border-color: var(--ps-ink-3); }

        .ps-hint { font-size: 13.5px; color: var(--ps-ink-3); margin: 0; }

        .ps-sum { display: flex; flex-direction: column; gap: 8px; }

        .ps-sum-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 6px 14px;
            align-items: start;
            padding: 13px 15px;
            background: var(--ps-surface);
            border: 1px solid var(--ps-line);
            border-radius: 10px;
        }

        .ps-sum-q {
            grid-column: 1 / -1;
            font-family: var(--ps-mono);
            font-size: 11.5px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--ps-ink-3);
        }

        .ps-sum-a { font-weight: 600; font-size: 16px; line-height: 1.35; }
        .ps-sum-a i { font-style: normal; font-family: var(--ps-mono); color: var(--ps-accent); margin-right: 6px; }
        .ps-sum-effect { grid-column: 1 / -1; font-size: 14px; color: var(--ps-ink-2); }

        .ps-sum-edit {
            font: inherit;
            font-size: 13.5px;
            font-weight: 500;
            padding: 5px 11px;
            background: transparent;
            border: 1px solid var(--ps-line-strong);
            color: var(--ps-ink-2);
            border-radius: 7px;
            cursor: pointer;
        }

        .ps-sum-edit:hover { color: var(--ps-ink); border-color: var(--ps-ink-3); }

        .ps-done {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--ps-accent-soft);
            border: 1px solid var(--ps-accent-line);
            color: var(--ps-ink);
        }

        .ps-done-label { font-family: var(--ps-mono); font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--ps-accent); }

        .ps-list { display: flex; flex-direction: column; gap: 9px; padding: 0; margin: 0; list-style: none; }
        .ps-list li { display: grid; grid-template-columns: 20px 1fr; gap: 10px; align-items: start; color: var(--ps-ink-2); }
        .ps-list .ps-num { font-family: var(--ps-mono); font-size: 12.5px; color: var(--ps-accent); padding-top: 3px; }

        .ps-divider { height: 1px; background: var(--ps-line); border: 0; margin: 0; }
    </style>

    <div class="ps" x-data="pricingSurvey(@js($questions), @js($saved))">
        @if ($answeredOnServer)
            <div class="ps-done">
                <span class="ps-done-label">Ответы получены</span>
                <span>{{ $savedAt }}@if ($savedBy) · {{ $savedBy }}@endif. Если что-то передумали — измените ответы и отправьте ещё раз, я возьму последний вариант.</span>
            </div>
        @endif

        <div class="ps-bar" x-show="step >= 0" x-cloak>
            <div class="ps-bar-head">
                <span x-text="step < questions.length ? 'Вопрос ' + (step + 1) + ' из ' + questions.length : 'Итог'"></span>
                <span x-text="answeredCount() + ' из ' + questions.length + ' отвечено'"></span>
            </div>
            <div class="ps-track"><div class="ps-track-fill" :style="'width:' + progress() + '%'"></div></div>
        </div>

        {{-- Вступление --}}
        <template x-if="step === -1">
            <div class="ps-screen">
                <p class="ps-eyebrow">Анкета по ценам</p>
                <h2 class="ps-h1">Я разобрался, почему правки не сохранились</h2>
                <p class="ps-lede">
                    13 августа вы поменяли цены у 55 товаров Spitzenreiter, и ничего не записалось.
                    Я поднял логи: <b>это ошибка на моей стороне</b>, а не ваша. Вы очистили колонки
                    «Курс валюты», «Опт, руб» и «Цена на сайт» — программа не смогла посчитать цену без
                    курса и оборвала загрузку, но показала зелёное «Импорт применён». Это я чиню в любом случае.
                </p>

                <hr class="ps-divider">

                <p class="ps-lede">Чтобы починить сразу правильно, задам 8 вопросов. Займёт 5 минут:</p>

                <ul class="ps-list">
                    <li><span class="ps-num">1</span><span>в каждом вопросе пишу, <b>как работает сейчас</b> и что изменится от вашего выбора;</span></li>
                    <li><span class="ps-num">2</span><span>где у меня есть мнение — ставлю пометку «советую»;</span></li>
                    <li><span class="ps-num">3</span><span>в конце покажу все ответы списком, и вы отправите их мне одной кнопкой.</span></li>
                </ul>

                <div class="ps-nav">
                    <button type="button" class="ps-btn ps-btn-primary" x-text="answeredCount() > 0 ? 'Продолжить' : 'Начать'" @click="start()"></button>
                </div>
            </div>
        </template>

        {{-- Вопросы --}}
        <template x-for="(q, i) in questions" :key="q.id">
            <template x-if="step === i">
                <div class="ps-screen">
                    <p class="ps-eyebrow" x-text="'Вопрос ' + (i + 1) + ' из ' + questions.length"></p>
                    <h2 class="ps-h2" x-text="q.title"></h2>

                    <div class="ps-note">
                        <span class="ps-note-label">Как сейчас</span>
                        <span x-text="q.now"></span>
                    </div>

                    <fieldset class="ps-options">
                        <template x-for="opt in q.options" :key="opt.key">
                            <label class="ps-option">
                                <input type="radio" :name="q.id" :value="opt.key" :checked="answers[q.id] === opt.key" @change="choose(q.id, opt.key)">
                                <span class="ps-mark" x-text="opt.key"></span>
                                <span class="ps-option-body">
                                    <template x-if="opt.recommended"><span class="ps-tag">советую</span></template>
                                    <span class="ps-option-title" x-text="opt.title"></span>
                                    <template x-if="opt.desc"><span class="ps-option-desc" x-text="opt.desc"></span></template>
                                    <template x-if="opt.calc"><span class="ps-calc" x-html="opt.calc"></span></template>
                                    <template x-if="opt.effect"><span class="ps-option-effect" x-html="opt.effect"></span></template>
                                </span>
                            </label>
                        </template>
                    </fieldset>

                    <div class="ps-nav" x-show="! editing">
                        <button type="button" class="ps-btn ps-btn-ghost" @click="go(step - 1)">Назад</button>
                        <button type="button" class="ps-btn ps-btn-primary" :disabled="! answers[q.id]" @click="go(step + 1)"
                                x-text="i === questions.length - 1 ? 'Посмотреть ответы' : 'Далее'"></button>
                    </div>

                    <div class="ps-nav" x-show="editing" x-cloak>
                        <button type="button" class="ps-btn ps-btn-ghost" @click="cancelEdit()">Отмена</button>
                        <button type="button" class="ps-btn ps-btn-primary" :disabled="! answers[q.id]" @click="finishEdit()">
                            Сохранить и вернуться к ответам
                        </button>
                    </div>

                    <p class="ps-hint" x-show="! answers[q.id]">Выберите один вариант, чтобы продолжить.</p>
                    <p class="ps-hint" x-show="editing && answers[q.id]" x-cloak>Правите один ответ — остальные останутся как были.</p>
                </div>
            </template>
        </template>

        {{-- Итог --}}
        <template x-if="step === questions.length">
            <div class="ps-screen">
                <p class="ps-eyebrow">Последний шаг</p>
                <h2 class="ps-h2">Ваши ответы</h2>
                <p class="ps-lede">Проверьте список. Если что-то выбрали не так — нажмите «Изменить» в нужной строке.</p>

                <div class="ps-sum">
                    <template x-for="(q, i) in questions" :key="q.id">
                        <div class="ps-sum-row">
                            <div class="ps-sum-q" x-text="(i + 1) + '. ' + q.short"></div>
                            <div class="ps-sum-a">
                                <i x-text="answers[q.id] || '—'"></i>
                                <span x-text="chosen(q) ? chosen(q).title : 'нет ответа'"></span>
                            </div>
                            <button type="button" class="ps-sum-edit" @click="edit(i)">Изменить</button>
                            <template x-if="chosen(q) && chosen(q).effect">
                                <div class="ps-sum-effect" x-html="chosen(q).effect"></div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="ps-nav">
                    <button type="button" class="ps-btn ps-btn-ghost" @click="go(questions.length - 1)">Назад</button>
                    <button type="button" class="ps-btn ps-btn-primary" :disabled="answeredCount() < questions.length || sending"
                            @click="submit()" x-text="sending ? 'Отправляю…' : 'Отправить ответы'"></button>
                </div>

                <p class="ps-hint" x-show="answeredCount() < questions.length">
                    Остались неотвеченные вопросы — откройте их через «Изменить».
                </p>
            </div>
        </template>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pricingSurvey', (questions, saved) => ({
                questions: questions,
                answers: {},
                step: -1,
                sending: false,
                editing: false,
                editId: null,
                editSnapshot: null,
                storageKey: 'intertooler-pricing-survey-v1',

                init() {
                    const stored = this.read();
                    const fromServer = saved && Object.keys(saved).length > 0;

                    this.answers = fromServer ? Object.assign({}, saved) : (stored.answers || {});
                    this.step = fromServer
                        ? this.questions.length
                        : (typeof stored.step === 'number' ? stored.step : -1);

                    this.$watch('answers', () => this.write());
                    this.$watch('step', () => this.write());
                },

                read() {
                    try {
                        return JSON.parse(window.localStorage.getItem(this.storageKey)) || {};
                    } catch (e) {
                        return {};
                    }
                },

                write() {
                    try {
                        window.localStorage.setItem(this.storageKey, JSON.stringify({
                            answers: this.answers,
                            step: this.step,
                        }));
                    } catch (e) {
                        // приватный режим — просто работаем без сохранения
                    }
                },

                choose(id, key) {
                    this.answers = Object.assign({}, this.answers, { [id]: key });
                },

                edit(index) {
                    const question = this.questions[index];

                    this.editing = true;
                    this.editId = question.id;
                    this.editSnapshot = this.answers[question.id] || null;
                    this.go(index);
                },

                finishEdit() {
                    this.stopEditing();
                    this.go(this.questions.length);
                },

                cancelEdit() {
                    if (this.editId) {
                        const restored = Object.assign({}, this.answers);

                        if (this.editSnapshot) {
                            restored[this.editId] = this.editSnapshot;
                        } else {
                            delete restored[this.editId];
                        }

                        this.answers = restored;
                    }

                    this.stopEditing();
                    this.go(this.questions.length);
                },

                stopEditing() {
                    this.editing = false;
                    this.editId = null;
                    this.editSnapshot = null;
                },

                chosen(q) {
                    return q.options.find((opt) => opt.key === this.answers[q.id]) || null;
                },

                answeredCount() {
                    return this.questions.filter((q) => this.answers[q.id]).length;
                },

                progress() {
                    if (this.step < 0) return 0;
                    return Math.round((this.step + 1) / (this.questions.length + 1) * 100);
                },

                start() {
                    const answered = this.answeredCount();
                    this.go(answered > 0 && answered < this.questions.length ? answered : 0);
                },

                go(next) {
                    this.step = Math.max(-1, Math.min(this.questions.length, next));

                    if (this.editing && this.questions[this.step]?.id !== this.editId) {
                        this.stopEditing();
                    }

                    this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },

                async submit() {
                    this.sending = true;

                    try {
                        await this.$wire.submit(this.answers);
                    } finally {
                        this.sending = false;
                    }
                },
            }));
        });
    </script>
</x-filament-panels::page>
