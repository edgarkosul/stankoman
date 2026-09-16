<x-filament-panels::page>
    <style>
        @if ($fonts)
            @font-face {
                font-family: "BQ Roboto Flex";
                font-weight: 100 1000;
                font-stretch: 25% 151%;
                font-display: swap;
                src: url("{{ $fonts['cyrillic'] }}") format("woff2");
                unicode-range: U+0301, U+0400-045F, U+0490-0491, U+04B0-04B1, U+2116;
            }

            @font-face {
                font-family: "BQ Roboto Flex";
                font-weight: 100 1000;
                font-stretch: 25% 151%;
                font-display: swap;
                src: url("{{ $fonts['latin'] }}") format("woff2");
                unicode-range: U+0000-00FF, U+2000-206F, U+20AC, U+2116, U+2122, U+2212;
            }
        @endif

        /*
         * Анкета говорит голосом витрины, а не панели: зелёный InterTooler,
         * его узкий Roboto Flex и чат, похожий на тот, что увидит покупатель.
         * Тема у админки светлая, тёмных вариантов здесь нет намеренно.
         */
        .bq {
            --bq-green: #0f6a24;
            --bq-green-ink: #0b531c;
            --bq-green-soft: #e8f1ea;
            --bq-green-line: #a8ccb1;
            --bq-red: #bd151b;
            --bq-red-soft: #fcebeb;
            --bq-red-line: #efb7b8;
            --bq-amber: #855700;
            --bq-amber-soft: #f8efd9;
            --bq-amber-line: #e2cd98;
            --bq-grey-soft: #eef1ef;
            --bq-ink: #141c17;
            --bq-ink-2: #4a5650;
            --bq-ink-3: #7a857e;
            --bq-line: #dbe1dc;
            --bq-line-2: #c2cbc4;
            --bq-surface: #ffffff;
            --bq-ground: #f3f6f3;
            --bq-display: "BQ Roboto Flex", "Roboto Flex", ui-sans-serif, system-ui, sans-serif;

            color: var(--bq-ink);
            font-size: 15.5px;
            line-height: 1.55;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .bq [x-cloak] { display: none !important; }
        .bq button { font: inherit; }
        .bq :focus-visible { outline: 2px solid var(--bq-green); outline-offset: 2px; }

        .bq-display {
            font-family: var(--bq-display);
            font-stretch: 70%;
            font-weight: 760;
            letter-spacing: -.005em;
            line-height: 1.04;
            text-wrap: balance;
            margin: 0;
        }

        .bq-eyebrow {
            font-size: 12px;
            font-weight: 650;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: var(--bq-green);
            margin: 0;
        }

        .bq p { margin: 0; }
        .bq-muted { color: var(--bq-ink-2); }
        .bq-start { align-self: flex-start; }
        .bq-stack-sm { display: flex; flex-direction: column; gap: 8px; }
        .bq-stack-lg { display: flex; flex-direction: column; gap: 28px; }
        .bq-top-right { display: flex; align-items: center; gap: 16px; }
        .bq-small { font-size: 13.5px; color: var(--bq-ink-3); margin: 0; }

        /* ---------- кнопки ---------- */
        .bq-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 650;
            font-size: 15px;
            padding: 11px 20px;
            border-radius: 10px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s, opacity .15s;
            white-space: nowrap;
        }

        .bq-btn-primary { background: var(--bq-green); color: #fff; }
        .bq-btn-primary:hover { background: var(--bq-green-ink); }
        .bq-btn-primary:disabled { opacity: .45; cursor: not-allowed; }
        .bq-btn-ghost { background: var(--bq-surface); color: var(--bq-ink-2); border-color: var(--bq-line-2); }
        .bq-btn-ghost:hover { color: var(--bq-ink); border-color: var(--bq-ink-3); }
        .bq-btn-link { background: none; border: 0; padding: 4px 0; color: var(--bq-green); font-weight: 600; cursor: pointer; }
        .bq-btn-link:hover { text-decoration: underline; text-underline-offset: 3px; }

        /* ---------- верхняя карта ---------- */
        .bq-top {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px 24px;
            flex-wrap: wrap;
            padding: 14px 16px;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 14px;
        }

        .bq-map { display: flex; gap: 18px; overflow-x: auto; padding: 5px; margin: -5px; max-width: calc(100% + 10px); }
        .bq-map-sec { display: flex; flex-direction: column; gap: 6px; flex: none; }
        .bq-map-label { font-size: 11px; font-weight: 650; letter-spacing: .06em; text-transform: uppercase; color: var(--bq-ink-3); white-space: nowrap; }
        .bq-map-cells { display: flex; gap: 4px; }

        .bq-cell {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--bq-line-2);
            background: var(--bq-surface);
            color: var(--bq-ink-2);
            font-size: 13px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
        }

        .bq-cell:hover { border-color: var(--bq-green); color: var(--bq-green); }
        .bq-cell.is-done { background: var(--bq-green); border-color: var(--bq-green); color: #fff; }
        .bq-cell.is-current { box-shadow: 0 0 0 2px var(--bq-surface), 0 0 0 4px var(--bq-ink); }

        .bq-save { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--bq-ink-3); white-space: nowrap; }
        .bq-save::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--bq-line-2); }
        .bq-save[data-state="saved"]::before { background: var(--bq-green); }
        .bq-save[data-state="saving"]::before { background: var(--bq-amber); animation: bq-pulse 1s infinite; }
        .bq-save[data-state="error"] { color: var(--bq-red); }
        .bq-save[data-state="error"]::before { background: var(--bq-red); }

        @keyframes bq-pulse { 50% { opacity: .35; } }

        /* ---------- вступление ---------- */
        .bq-intro {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr);
            gap: 28px;
            align-items: start;
        }

        .bq-intro-main { display: flex; flex-direction: column; gap: 18px; padding-top: 6px; }
        .bq-intro h2 { font-size: clamp(36px, 4.4vw, 58px); }
        .bq-lede { font-size: 17px; color: var(--bq-ink-2); margin: 0; max-width: 60ch; }

        .bq-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
        .bq-steps li { display: grid; grid-template-columns: 28px 1fr; gap: 10px; align-items: start; color: var(--bq-ink-2); }
        .bq-steps b { color: var(--bq-ink); font-weight: 650; }

        .bq-steps i {
            font-style: normal;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--bq-green-soft);
            color: var(--bq-green);
            font-size: 14px;
        }

        .bq-intro-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

        .bq-report {
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .bq-report-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .bq-report-head strong { font-family: var(--bq-display); font-stretch: 80%; font-size: 21px; font-weight: 720; }

        .bq-bar { display: flex; gap: 3px; height: 14px; }
        .bq-bar span { border-radius: 3px; }
        .bq-k-ok { background: var(--bq-green); }
        .bq-k-partial { background: #c79a2b; }
        .bq-k-handoff { background: #9aa59d; }
        .bq-k-invented { background: var(--bq-red); }

        .bq-legend { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 14px; margin: 0; padding: 0; list-style: none; }
        .bq-legend li { display: grid; grid-template-columns: auto 1fr; column-gap: 10px; align-items: baseline; }
        .bq-legend b { font-family: var(--bq-display); font-stretch: 70%; font-size: 32px; font-weight: 760; line-height: 1; font-variant-numeric: tabular-nums; }
        .bq-legend span { font-weight: 600; font-size: 14px; line-height: 1.3; }
        .bq-legend small { grid-column: 2; font-size: 12.5px; color: var(--bq-ink-3); line-height: 1.35; }

        .bq-quotes { display: flex; flex-direction: column; gap: 12px; border-top: 1px solid var(--bq-line); padding-top: 14px; }

        .bq-sections { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }

        .bq-sec-tile {
            text-align: left;
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 14px;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 12px;
            cursor: pointer;
            transition: border-color .15s;
        }

        .bq-sec-tile:hover { border-color: var(--bq-green); }
        .bq-sec-tile strong { font-weight: 650; line-height: 1.25; }
        .bq-sec-meter { height: 4px; border-radius: 2px; background: var(--bq-grey-soft); overflow: hidden; }
        .bq-sec-meter span { display: block; height: 100%; background: var(--bq-green); transition: width .3s; }

        /* ---------- чат (превью) ---------- */
        .bq-chat {
            display: flex;
            flex-direction: column;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 18px 40px -28px rgba(15, 50, 25, .45);
        }

        .bq-chat-head { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: var(--bq-green); color: #fff; }
        .bq-chat-ava { width: 34px; height: 34px; border-radius: 50%; background: rgba(255, 255, 255, .16); display: grid; place-items: center; flex: none; }
        .bq-chat-head strong { display: block; font-size: 14.5px; line-height: 1.2; }
        .bq-chat-head small { display: block; font-size: 12px; opacity: .78; }

        .bq-chat-body { display: flex; flex-direction: column; gap: 12px; padding: 14px; background: var(--bq-ground); min-height: 180px; }

        .bq-msg { max-width: 92%; padding: 10px 13px; border-radius: 14px; font-size: 14.5px; line-height: 1.45; white-space: pre-line; }
        .bq-msg-buyer { align-self: flex-end; background: var(--bq-green); color: #fff; border-bottom-right-radius: 4px; }
        .bq-msg-bot { align-self: flex-start; background: var(--bq-surface); border: 1px solid var(--bq-line); border-bottom-left-radius: 4px; }

        .bq-turn { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; transition: opacity .25s; }
        .bq-turn.is-old { opacity: .5; }
        .bq-turn.is-old .bq-msg { text-decoration: line-through; text-decoration-color: rgba(189, 21, 27, .45); }
        .bq-turn.is-new .bq-msg-bot { border-color: var(--bq-green-line); box-shadow: 0 0 0 3px var(--bq-green-soft); }

        .bq-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 650;
            letter-spacing: .02em;
            padding: 2px 9px;
            border-radius: 999px;
            border: 1px solid transparent;
        }

        .bq-chip[data-kind="invented"] { background: var(--bq-red-soft); color: var(--bq-red); border-color: var(--bq-red-line); }
        .bq-chip[data-kind="handoff"] { background: var(--bq-grey-soft); color: var(--bq-ink-2); border-color: var(--bq-line-2); }
        .bq-chip[data-kind="partial"] { background: var(--bq-amber-soft); color: var(--bq-amber); border-color: var(--bq-amber-line); }
        .bq-chip[data-kind="ok"],
        .bq-chip[data-kind="after"] { background: var(--bq-green-soft); color: var(--bq-green-ink); border-color: var(--bq-green-line); }

        .bq-why { font-size: 13px; color: var(--bq-red); margin: 0; max-width: 92%; }
        .bq-chat-foot { font-size: 12.5px; color: var(--bq-ink-3); padding: 10px 14px; border-top: 1px solid var(--bq-line); margin: 0; }

        /* ---------- экран вопроса ---------- */
        .bq-q { display: grid; grid-template-columns: minmax(0, 1fr) minmax(300px, 380px); grid-template-areas: "head chat" "form chat"; gap: 18px 32px; align-items: start; }
        .bq-q-head { grid-area: head; display: flex; flex-direction: column; gap: 10px; }
        .bq-q-head h2 { font-size: clamp(28px, 3vw, 38px); }
        .bq-q-form { grid-area: form; display: flex; flex-direction: column; gap: 18px; }
        .bq-q-aside { grid-area: chat; position: sticky; top: 5.5rem; display: flex; flex-direction: column; gap: 12px; }

        .bq-context { margin: 0; color: var(--bq-ink-2); max-width: 68ch; }

        .bq-kraton {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 4px 12px;
            padding: 12px 14px;
            background: var(--bq-surface);
            border: 1px dashed var(--bq-line-2);
            border-radius: 12px;
            font-size: 14.5px;
        }

        .bq-kraton span:first-child { grid-row: span 2; font-family: var(--bq-display); font-stretch: 70%; font-weight: 760; font-size: 13px; letter-spacing: .04em; color: var(--bq-ink-3); text-transform: uppercase; padding-top: 2px; }
        .bq-kraton b { font-weight: 650; }

        .bq-field { display: flex; flex-direction: column; gap: 9px; border: 0; padding: 0; margin: 0; min-width: 0; }
        .bq-label { font-weight: 650; font-size: 15.5px; padding: 0; }
        .bq-hint { margin: -4px 0 0; font-size: 14px; color: var(--bq-ink-2); max-width: 70ch; }

        /* Флекс, а не сетка: третий вариант из трёх растягивается на строку, а не висит сиротой. */
        .bq-choices { display: flex; flex-wrap: wrap; gap: 8px; }
        .bq-choices > .bq-choice { flex: 1 1 240px; }

        .bq-choice {
            position: relative;
            display: grid;
            grid-template-columns: 20px 1fr;
            gap: 10px;
            align-items: start;
            padding: 11px 13px;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 11px;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            font-size: 14.5px;
            line-height: 1.4;
        }

        .bq-choice:hover { border-color: var(--bq-line-2); }
        .bq-choice input { position: absolute; opacity: 0; pointer-events: none; }

        .bq-dot { width: 20px; height: 20px; border-radius: 50%; border: 1.5px solid var(--bq-line-2); background: var(--bq-surface); margin-top: 1px; transition: all .15s; }
        .bq-choice:has(input:checked) { border-color: var(--bq-green); background: var(--bq-green-soft); }
        .bq-choice:has(input:checked) .bq-dot { border-color: var(--bq-green); background: var(--bq-green); box-shadow: inset 0 0 0 4px var(--bq-green-soft); }
        .bq-choice:has(input:focus-visible) { outline: 2px solid var(--bq-green); outline-offset: 2px; }

        .bq-chips { display: flex; flex-wrap: wrap; gap: 7px; }

        .bq-tick {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 13px;
            border-radius: 999px;
            border: 1px solid var(--bq-line-2);
            background: var(--bq-surface);
            font-size: 14px;
            cursor: pointer;
            position: relative;
            transition: all .15s;
        }

        .bq-tick input { position: absolute; opacity: 0; pointer-events: none; }
        .bq-tick::before { content: "+"; font-weight: 700; color: var(--bq-ink-3); width: 10px; text-align: center; }
        .bq-tick:has(input:checked) { background: var(--bq-green); border-color: var(--bq-green); color: #fff; }
        .bq-tick:has(input:checked)::before { content: "✓"; color: #fff; }
        .bq-tick:has(input:focus-visible) { outline: 2px solid var(--bq-green); outline-offset: 2px; }

        .bq-input {
            width: 100%;
            font: inherit;
            font-size: 15px;
            color: var(--bq-ink);
            padding: 10px 12px;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line-2);
            border-radius: 10px;
            resize: vertical;
            transition: border-color .15s, box-shadow .15s;
        }

        .bq-input::placeholder { color: var(--bq-ink-3); }
        .bq-input:focus { outline: none; border-color: var(--bq-green); box-shadow: 0 0 0 3px var(--bq-green-soft); }

        .bq-article { background: var(--bq-surface); border: 1px solid var(--bq-line); border-radius: 14px; padding: 18px 20px; display: flex; flex-direction: column; gap: 10px; }
        .bq-article p { margin: 0; font-size: 15px; max-width: 70ch; }

        .bq-site-field { padding: 14px 16px; background: var(--bq-surface); border: 1px solid var(--bq-line); border-radius: 14px; }

        .bq-nav { display: flex; align-items: center; gap: 10px; padding-top: 6px; flex-wrap: wrap; }
        .bq-nav .bq-btn-primary { margin-left: auto; min-width: 11rem; }

        .bq-aside-card { padding: 16px; background: var(--bq-surface); border: 1px solid var(--bq-line); border-radius: 16px; display: flex; flex-direction: column; gap: 10px; }

        /* ---------- итог ---------- */
        .bq-sum { display: flex; flex-direction: column; gap: 22px; }
        .bq-sum-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px 28px; flex-wrap: wrap; }
        .bq-sum-head h2 { font-size: clamp(32px, 3.6vw, 46px); }

        .bq-done {
            display: flex;
            gap: 14px;
            align-items: center;
            padding: 14px 18px;
            border-radius: 14px;
            background: var(--bq-green-soft);
            border: 1px solid var(--bq-green-line);
        }

        .bq-done-mark { width: 34px; height: 34px; border-radius: 50%; background: var(--bq-green); color: #fff; display: grid; place-items: center; flex: none; font-weight: 700; }

        .bq-sum-sec { display: flex; flex-direction: column; gap: 8px; }
        .bq-sum-sec h3 { margin: 0; font-size: 12px; letter-spacing: .09em; text-transform: uppercase; color: var(--bq-ink-3); font-weight: 650; }

        .bq-row {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr) auto;
            gap: 4px 14px;
            align-items: start;
            padding: 12px 14px;
            background: var(--bq-surface);
            border: 1px solid var(--bq-line);
            border-radius: 12px;
        }

        .bq-row-num { font-family: var(--bq-display); font-stretch: 70%; font-weight: 760; font-size: 22px; line-height: 1.1; color: var(--bq-ink-3); font-variant-numeric: tabular-nums; }
        .bq-row.is-done .bq-row-num { color: var(--bq-green); }
        .bq-row-title { font-weight: 650; }
        .bq-row-reply { grid-column: 2; font-size: 14px; color: var(--bq-ink-2); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .bq-row-empty { grid-column: 2; font-size: 14px; color: var(--bq-ink-3); }

        @media (max-width: 1100px) {
            .bq-intro { grid-template-columns: minmax(0, 1fr); }
            .bq-q { grid-template-columns: minmax(0, 1fr); grid-template-areas: "head" "chat" "form"; }
            .bq-q-aside { position: static; }
        }

        @media (max-width: 560px) {
            .bq-legend { grid-template-columns: minmax(0, 1fr); }
            .bq-row { grid-template-columns: 28px minmax(0, 1fr); }
            .bq-row > .bq-btn-link { grid-column: 2; justify-self: start; }
            .bq-nav .bq-btn-primary { margin-left: 0; flex: 1; }
        }

        @media (prefers-reduced-motion: reduce) {
            .bq *, .bq *::before { transition: none !important; animation: none !important; }
        }
    </style>

    <div class="bq" x-data="botKnowledgeSurvey(@js($config))" wire:ignore>

        {{-- Карта вопросов и отметка сохранения --}}
        <div class="bq-top" x-show="step >= 0" x-cloak>
            <nav class="bq-map" aria-label="Вопросы по разделам">
                <template x-for="sec in bySection" :key="sec.id">
                    <div class="bq-map-sec">
                        <span class="bq-map-label" x-text="sec.title"></span>
                        <div class="bq-map-cells">
                            <template x-for="item in sec.items" :key="item.q.id">
                                <button type="button" class="bq-cell"
                                        :class="{ 'is-done': isAnswered(item.q), 'is-current': step === item.i }"
                                        :aria-label="'Вопрос ' + (item.i + 1) + ': ' + item.q.short"
                                        :title="item.q.short"
                                        @click="go(item.i)" x-text="item.i + 1"></button>
                            </template>
                        </div>
                    </div>
                </template>
            </nav>

            <div class="bq-top-right">
                <span class="bq-save" :data-state="saveState" x-text="saveText()" aria-live="polite"></span>
                <button type="button" class="bq-btn-link" x-show="step < questions.length" @click="go(questions.length)">К итогам</button>
            </div>
        </div>

        {{-- Вступление --}}
        <template x-if="step === -1">
            <div class="bq-stack-lg">
                <template x-if="submitted">
                    <div class="bq-done">
                        <span class="bq-done-mark">✓</span>
                        <span>
                            Ответы отправлены <b x-text="submitted.at"></b><span x-show="submitted.by" x-text="' · ' + submitted.by"></span>.
                            Можно дополнить и отправить ещё раз — возьмём последний вариант.
                        </span>
                    </div>
                </template>

                <div class="bq-intro">
                    <div class="bq-intro-main">
                        <p class="bq-eyebrow">Помощник в чате InterTooler · перед запуском</p>
                        <h2 class="bq-display">Научим бота отвечать так, как отвечает ваш магазин</h2>
                        <p class="bq-lede">
                            Бот говорит с покупателями только тем, что магазин о себе написал.
                            Где на сайте ответа нет, он зовёт менеджера — или додумывает. Настройкой
                            это не лечится, только вашими ответами.
                        </p>

                        <ul class="bq-steps">
                            <li><i>1</i><span><b>Выбирайте варианты.</b> Справа сразу видно, что бот скажет покупателю после вашего ответа.</span></li>
                            <li><i>2</i><span><b>Где вариантов не хватает — пишите своими словами.</b> Красиво не нужно, перепишем сами.</span></li>
                            <li><i>3</i><span><b>Ответы сохраняются сами.</b> Можно пропускать, закрыть страницу и вернуться. В конце — одна кнопка «Отправить».</span></li>
                        </ul>

                        <div class="bq-intro-actions">
                            <button type="button" class="bq-btn bq-btn-primary" @click="start()"
                                    x-text="answeredCount() > 0 ? 'Продолжить с вопроса ' + (firstOpen() + 1) : 'Начать'"></button>
                            <span class="bq-small" x-text="questions.length + ' экранов · около 15 минут'"></span>
                        </div>
                    </div>

                    <aside class="bq-report">
                        <div class="bq-report-head">
                            <strong x-text="'Проверка на ' + intro.total + ' вопросах покупателей'"></strong>
                            <span class="bq-small" x-text="intro.checked_at"></span>
                        </div>

                        <div class="bq-bar" role="img" :aria-label="intro.tally.map(t => t.count + ' — ' + t.label).join(', ')">
                            <template x-for="t in intro.tally" :key="t.kind">
                                <span :class="'bq-k-' + t.kind" :style="'flex:' + t.count"></span>
                            </template>
                        </div>

                        <ul class="bq-legend">
                            <template x-for="t in intro.tally" :key="t.kind">
                                <li>
                                    <b :style="t.kind === 'invented' ? 'color: var(--bq-red)' : ''" x-text="t.count"></b>
                                    <span x-text="t.label"></span>
                                    <small x-text="t.hint"></small>
                                </li>
                            </template>
                        </ul>

                        <div class="bq-quotes">
                            <span class="bq-chip bq-start" data-kind="invented">Что бот уже пообещал бы от имени магазина</span>
                            <template x-for="quote in intro.quotes" :key="quote.ask">
                                <div class="bq-stack-sm">
                                    <div class="bq-msg bq-msg-buyer" x-text="quote.ask"></div>
                                    <div class="bq-msg bq-msg-bot" x-text="quote.text"></div>
                                </div>
                            </template>
                        </div>
                    </aside>
                </div>

                <div class="bq-sections">
                    <template x-for="sec in bySection" :key="sec.id">
                        <button type="button" class="bq-sec-tile" @click="go(sec.items[0].i)">
                            <strong x-text="sec.title"></strong>
                            <span class="bq-small" x-text="sectionDone(sec) + ' из ' + sec.items.length"></span>
                            <span class="bq-sec-meter"><span :style="'width:' + Math.round(sectionDone(sec) / sec.items.length * 100) + '%'"></span></span>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        {{-- Вопросы --}}
        <template x-for="(q, i) in questions" :key="q.id">
            <template x-if="step === i">
                <section class="bq-q">
                    <header class="bq-q-head">
                        <p class="bq-eyebrow" x-text="sectionTitle(q) + ' · ' + (i + 1) + ' из ' + questions.length"></p>
                        <h2 class="bq-display" x-text="q.title"></h2>
                    </header>

                    <div class="bq-q-form">
                        <template x-if="q.context">
                            <p class="bq-context" x-text="q.context"></p>
                        </template>

                        <template x-if="q.kraton">
                            <div class="bq-kraton">
                                <span>KratonShop</span>
                                <b>Для KratonShop вы ответили так. Здесь так же?</b>
                                <span x-text="q.kraton"></span>
                            </div>
                        </template>

                        <template x-if="q.article">
                            <article class="bq-article">
                                <p class="bq-eyebrow" x-text="q.article.category"></p>
                                <template x-for="(paragraph, p) in q.article.paragraphs" :key="p">
                                    <p x-text="paragraph"></p>
                                </template>
                            </article>
                        </template>

                        <template x-for="f in q.fields" :key="q.id + '.' + f.id">
                            <div x-show="visible(q, f)" x-transition.opacity.duration.150ms :class="q.section === 'site' ? 'bq-site-field' : ''">
                                <template x-if="f.type === 'choice'">
                                    <fieldset class="bq-field">
                                        <legend class="bq-label" x-text="f.label"></legend>
                                        <template x-if="f.hint"><p class="bq-hint" x-text="f.hint"></p></template>
                                        <div class="bq-choices">
                                            <template x-for="o in f.options" :key="o.key">
                                                <label class="bq-choice">
                                                    <input type="radio" :name="q.id + '-' + f.id" :value="o.key" x-model="answers[q.id][f.id]">
                                                    <span class="bq-dot" aria-hidden="true"></span>
                                                    <span x-text="o.title"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </fieldset>
                                </template>

                                <template x-if="f.type === 'multi'">
                                    <fieldset class="bq-field">
                                        <legend class="bq-label" x-text="f.label"></legend>
                                        <div class="bq-chips">
                                            <template x-for="o in f.options" :key="o.key">
                                                <label class="bq-tick">
                                                    <input type="checkbox" :value="o.key" x-model="answers[q.id][f.id]">
                                                    <span x-text="o.title"></span>
                                                </label>
                                            </template>
                                        </div>
                                        <template x-if="f.other">
                                            <input type="text" class="bq-input" :id="'bq-' + q.id + '-' + f.id + '-other'"
                                                   :placeholder="f.other + ' — через запятую'" x-model="answers[q.id][f.id + '_other']" maxlength="2000">
                                        </template>
                                    </fieldset>
                                </template>

                                <template x-if="f.type === 'text'">
                                    <div class="bq-field">
                                        <label class="bq-label" :for="'bq-' + q.id + '-' + f.id" x-text="f.label"></label>
                                        <textarea class="bq-input" :id="'bq-' + q.id + '-' + f.id" :rows="f.rows || 2"
                                                  :placeholder="f.placeholder" x-model="answers[q.id][f.id]" maxlength="2000"></textarea>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="bq-field">
                            <button type="button" class="bq-btn-link bq-start" x-show="! noteOpen(q)" @click="notes[q.id] = true">
                                + Комментарий своими словами
                            </button>
                            <template x-if="noteOpen(q)">
                                <div class="bq-field">
                                    <label class="bq-label" :for="'bq-' + q.id + '-note'">Комментарий</label>
                                    <textarea class="bq-input" :id="'bq-' + q.id + '-note'" rows="3" maxlength="2000"
                                              placeholder="Если ответ не укладывается в варианты — напишите здесь"
                                              x-model="answers[q.id].note"></textarea>
                                </div>
                            </template>
                        </div>

                        <div class="bq-nav">
                            <button type="button" class="bq-btn bq-btn-ghost" @click="go(i - 1)">Назад</button>
                            <button type="button" class="bq-btn bq-btn-primary" @click="next(i)" x-text="nextLabel(q, i)"></button>
                        </div>
                    </div>

                    <aside class="bq-q-aside">
                        <template x-if="q.now.kind !== 'site'">
                            <div class="bq-chat">
                                <div class="bq-chat-head">
                                    <span class="bq-chat-ava" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v2m0 14v2M5 12H3m18 0h-2M7 7 5.6 5.6m12.8 12.8L17 17M7 17l-1.4 1.4M18.4 5.6 17 7"/><circle cx="12" cy="12" r="3.2"/></svg>
                                    </span>
                                    <span>
                                        <strong>Консультант InterTooler</strong>
                                        <small>так это увидит покупатель</small>
                                    </span>
                                </div>

                                <div class="bq-chat-body">
                                    <div class="bq-msg bq-msg-buyer" x-text="q.ask"></div>

                                    <div class="bq-turn" :class="reply(q) ? 'is-old' : ''">
                                        <span class="bq-chip" :data-kind="q.now.kind" x-text="kindLabel(q.now.kind)"></span>
                                        <div class="bq-msg bq-msg-bot" x-text="q.now.text"></div>
                                        <template x-if="q.now.why && ! reply(q)">
                                            <p class="bq-why" x-text="q.now.why"></p>
                                        </template>
                                    </div>

                                    <template x-if="reply(q)">
                                        <div class="bq-turn is-new" x-transition.opacity>
                                            <span class="bq-chip" data-kind="after">После вашего ответа</span>
                                            <div class="bq-msg bq-msg-bot" x-text="reply(q)"></div>
                                        </div>
                                    </template>
                                </div>

                                <p class="bq-chat-foot" x-text="q.article
                                    ? 'Это настоящий ответ бота по статье слева.'
                                    : 'Бот перескажет своими словами, но факты возьмёт только ваши.'"></p>
                            </div>
                        </template>

                        <template x-if="q.now.kind === 'site'">
                            <div class="bq-aside-card">
                                <span class="bq-chip bq-start" data-kind="invented">Видно покупателям уже сейчас</span>
                                <p class="bq-muted" x-text="q.now.text"></p>
                                <p class="bq-small" x-text="siteChosen(q) + ' из ' + q.fields.length + ' отмечено'"></p>
                            </div>
                        </template>
                    </aside>
                </section>
            </template>
        </template>

        {{-- Итог --}}
        <template x-if="step === questions.length">
            <section class="bq-sum">
                <div class="bq-sum-head">
                    <div class="bq-stack-sm">
                        <p class="bq-eyebrow">Последний шаг</p>
                        <h2 class="bq-display">Проверьте и отправьте</h2>
                        <p class="bq-muted" x-text="answeredCount() === questions.length
                            ? 'Отвечено всё. Спасибо!'
                            : 'Отвечено ' + answeredCount() + ' из ' + questions.length + '. Можно отправить и так — остальное допишете потом и отправите ещё раз.'"></p>
                    </div>

                    <button type="button" class="bq-btn bq-btn-primary" :disabled="sending || answeredCount() === 0" @click="submit()"
                            x-text="sending ? 'Отправляю…' : 'Отправить ответы'"></button>
                </div>

                <template x-if="submitted">
                    <div class="bq-done">
                        <span class="bq-done-mark">✓</span>
                        <span>
                            Отправлено <b x-text="submitted.at"></b><span x-show="submitted.by" x-text="' · ' + submitted.by"></span>,
                            <span x-text="submitted.answered + ' из ' + questions.length"></span> ответов.
                            Изменили что-то после — отправьте ещё раз.
                        </span>
                    </div>
                </template>

                <template x-for="sec in bySection" :key="sec.id">
                    <div class="bq-sum-sec">
                        <h3 x-text="sec.title"></h3>
                        <template x-for="item in sec.items" :key="item.q.id">
                            <div class="bq-row" :class="isAnswered(item.q) ? 'is-done' : ''">
                                <span class="bq-row-num" x-text="item.i + 1"></span>
                                <span class="bq-row-title" x-text="item.q.short"></span>
                                <button type="button" class="bq-btn-link" @click="go(item.i, true)"
                                        x-text="isAnswered(item.q) ? 'Изменить' : (item.q.article ? 'Проверить' : 'Ответить')"></button>
                                <template x-if="reply(item.q)">
                                    <span class="bq-row-reply" x-text="'Бот: ' + reply(item.q)"></span>
                                </template>
                                <template x-if="! reply(item.q)">
                                    <span class="bq-row-empty" x-text="isAnswered(item.q) ? 'Ответ записан' : 'Нет ответа'"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="bq-nav">
                    <button type="button" class="bq-btn bq-btn-ghost" @click="go(questions.length - 1)">Назад</button>
                    <button type="button" class="bq-btn bq-btn-primary" :disabled="sending || answeredCount() === 0" @click="submit()"
                            x-text="sending ? 'Отправляю…' : 'Отправить ответы'"></button>
                </div>
            </section>
        </template>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('botKnowledgeSurvey', (config) => ({
                sections: config.sections,
                questions: config.questions,
                intro: config.intro,
                bySection: [],
                answers: {},
                notes: {},
                step: -1,
                backToSummary: false,
                saveState: 'idle',
                savedLabel: config.savedLabel,
                submitted: config.submitted,
                sending: false,
                saving: false,
                resave: false,
                timer: null,
                rootEl: null,
                storageKey: 'intertooler-bot-survey-v1',

                init() {
                    // Корень запоминается сразу: из обработчика клика $el — кнопка,
                    // которую x-if уже убрал к моменту прокрутки.
                    this.rootEl = this.$el;

                    const local = this.read();
                    let answers = config.answers || {};

                    // Своя копия новее серверной — значит, последнее сохранение
                    // не дошло (закрыли вкладку, пропал интернет). Досылаем.
                    const localIsNewer = local.answers && (local.updatedAt || 0) > (config.draftSavedAt || 0);

                    if (localIsNewer) {
                        answers = local.answers;
                    }

                    this.answers = this.normalize(answers);
                    this.bySection = this.sections.map((sec) => ({
                        ...sec,
                        items: this.questions.map((q, i) => ({ q, i })).filter((item) => item.q.section === sec.id),
                    }));

                    if (typeof local.step === 'number') {
                        this.step = Math.max(-1, Math.min(this.questions.length, local.step));
                    }

                    this.$watch('answers', () => {
                        this.write(true);
                        this.queueSave();
                    });
                    this.$watch('step', () => this.write(false));

                    if (localIsNewer) {
                        this.queueSave();
                    }
                },

                normalize(raw) {
                    const result = {};

                    for (const q of this.questions) {
                        const given = Object.assign({}, raw[q.id] || {});

                        for (const f of q.fields) {
                            if (f.type === 'multi') {
                                given[f.id] = Array.isArray(given[f.id]) ? given[f.id] : [];

                                if (f.other && typeof given[f.id + '_other'] !== 'string') {
                                    given[f.id + '_other'] = '';
                                }
                            } else if (typeof given[f.id] !== 'string') {
                                given[f.id] = '';
                            }
                        }

                        if (typeof given.note !== 'string') {
                            given.note = '';
                        }

                        result[q.id] = given;
                    }

                    return result;
                },

                /* ---- хранение ---- */

                read() {
                    try {
                        return JSON.parse(window.localStorage.getItem(this.storageKey)) || {};
                    } catch (e) {
                        return {};
                    }
                },

                write(answersChanged) {
                    try {
                        const previous = this.read();

                        window.localStorage.setItem(this.storageKey, JSON.stringify({
                            answers: this.plain(),
                            step: this.step,
                            updatedAt: answersChanged ? Date.now() : (previous.updatedAt || 0),
                        }));
                    } catch (e) {
                        // приватный режим — живём на серверном черновике
                    }
                },

                plain() {
                    return JSON.parse(JSON.stringify(this.answers));
                },

                queueSave() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.save(), 1200);
                },

                async save() {
                    if (this.saving) {
                        this.resave = true;

                        return;
                    }

                    this.saving = true;
                    this.saveState = 'saving';

                    try {
                        const result = await this.$wire.saveDraft(this.plain());
                        this.savedLabel = result.savedLabel;
                        this.saveState = 'saved';
                    } catch (e) {
                        this.saveState = 'error';
                        setTimeout(() => this.queueSave(), 5000);
                    } finally {
                        this.saving = false;

                        if (this.resave) {
                            this.resave = false;
                            this.queueSave();
                        }
                    }
                },

                saveText() {
                    if (this.saveState === 'saving') return 'Сохраняю…';
                    if (this.saveState === 'error') return 'Не сохранилось — повторю сам';

                    return this.savedLabel ? 'Сохранено в ' + this.savedLabel : 'Ответы сохраняются сами';
                },

                async submit() {
                    clearTimeout(this.timer);
                    this.sending = true;

                    try {
                        const result = await this.$wire.submit(this.plain());

                        if (result.ok) {
                            this.submitted = result.submitted;
                            this.saveState = 'saved';
                        }
                    } finally {
                        this.sending = false;
                    }
                },

                /* ---- ответы ---- */

                filled(value) {
                    return Array.isArray(value) ? value.length > 0 : String(value ?? '').trim() !== '';
                },

                visible(q, f) {
                    if (! f.showIf) return true;

                    return f.showIf.in.includes(this.answers[q.id][f.showIf.field]);
                },

                isAnswered(q) {
                    const given = this.answers[q.id];

                    return q.fields.some((f) => this.visible(q, f)
                        && (this.filled(given[f.id]) || this.filled(given[f.id + '_other'])));
                },

                answeredCount() {
                    return this.questions.filter((q) => this.isAnswered(q)).length;
                },

                sectionDone(sec) {
                    return sec.items.filter((item) => this.isAnswered(item.q)).length;
                },

                siteChosen(q) {
                    return q.fields.filter((f) => this.filled(this.answers[q.id][f.id])).length;
                },

                // Те же правила, что BotKnowledgeQuestionnaire::botReply(): владелец
                // одобряет то, что видит здесь, а выгрузка отдаёт то, что там.
                reply(q) {
                    const given = this.answers[q.id];
                    const parts = [];

                    for (const f of q.fields) {
                        if (! this.visible(q, f)) continue;

                        const value = given[f.id];

                        if (f.type === 'choice') {
                            const option = f.options.find((o) => o.key === value);

                            if (option && option.says) parts.push(option.says);

                            continue;
                        }

                        if (! f.says) continue;

                        if (f.type === 'multi') {
                            const titles = f.options.filter((o) => value.includes(o.key)).map((o) => o.title);
                            const other = given[f.id + '_other'];

                            if (f.other && this.filled(other)) titles.push(other.trim());
                            if (titles.length) parts.push(f.says.replace('{list}', titles.join(', ')));

                            continue;
                        }

                        if (this.filled(value)) {
                            parts.push(f.says.replace('{value}', value.trim().replace(/[.\s]+$/, '')));
                        }
                    }

                    return parts.join(' ');
                },

                noteOpen(q) {
                    return this.notes[q.id] === true || this.filled(this.answers[q.id].note);
                },

                kindLabel(kind) {
                    return {
                        invented: 'Сейчас: додумывает',
                        handoff: 'Сейчас: зовёт менеджера',
                        partial: 'Сейчас: отвечает не полностью',
                        ok: 'Сейчас: отвечает по статье',
                    }[kind] || '';
                },

                sectionTitle(q) {
                    return (this.sections.find((sec) => sec.id === q.section) || {}).title || '';
                },

                /* ---- навигация ---- */

                firstOpen() {
                    const index = this.questions.findIndex((q) => ! this.isAnswered(q));

                    return index === -1 ? 0 : index;
                },

                start() {
                    this.go(this.firstOpen());
                },

                nextLabel(q, i) {
                    if (this.backToSummary || i === this.questions.length - 1) return 'К итогам';

                    return this.isAnswered(q) ? 'Дальше' : 'Пропустить';
                },

                next(i) {
                    this.go(this.backToSummary ? this.questions.length : i + 1);
                },

                go(index, fromSummary = false) {
                    this.step = Math.max(-1, Math.min(this.questions.length, index));
                    this.backToSummary = fromSummary;

                    if (this.step === -1 || this.step === this.questions.length) {
                        this.backToSummary = false;
                    }

                    this.$nextTick(() => {
                        const top = this.rootEl.getBoundingClientRect().top + window.scrollY - 88;

                        if (window.scrollY > top) {
                            window.scrollTo({ top, behavior: 'smooth' });
                        }
                    });
                },
            }));
        });
    </script>
</x-filament-panels::page>
