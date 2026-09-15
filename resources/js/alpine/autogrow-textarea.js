/**
 * Поле ввода, растущее по содержимому до потолка в несколько строк.
 *
 * Высота считается и ставится inline — и именно поэтому компонент сложнее,
 * чем `$el.style.height = $el.scrollHeight`. Поле живёт внутри Livewire,
 * а тот на каждом ответе сервера сверяет разметку с серверной и сносит
 * атрибуты, которых в ней нет (`patchAttributes` в морфе). Серверный
 * `<textarea>` рендерится без `style`, значит посчитанная высота стирается
 * при каждом ответе — а панель чата ещё и опрашивает сервер по таймеру
 * (`wire:poll`), то есть поле схлопывалось бы в одну строку прямо под
 * руками у пишущего.
 *
 * Поэтому за атрибутом следит наблюдатель и возвращает высоту на место.
 * Зацикливания нет: он реагирует только на ПУСТУЮ высоту, а сам пересчёт
 * всегда оставляет её непустой.
 *
 * Возврат поля в одну строку после отправки висит не на наблюдателе, а на
 * `x-effect` рядом с полем, и это не дубль. Наблюдатель сработает, только
 * если морф до поля дошёл, — а Livewire умеет обновлять разметку кусками
 * и подвал с формой может не тронуть вовсе. Тогда `style` уцелеет, поле
 * останется в четыре строки, и опустевшим его покажет только слежение
 * за самим значением.
 *
 * @param {number} [maxRows] потолок в строках; дальше поле начинает скроллиться
 */
export default (maxRows = 4) => ({
    observer: null,

    init() {
        this.fit();

        this.observer = new MutationObserver(() => {
            if (!this.$el.style.height) this.fit();
        });

        this.observer.observe(this.$el, { attributes: true, attributeFilter: ['style'] });
    },

    destroy() {
        // Панель закрывают и открывают заново; брошенный наблюдатель
        // остался бы висеть на выброшенном из документа поле.
        this.observer?.disconnect();
        this.observer = null;
    },

    fit() {
        const el = this.$el;
        const style = getComputedStyle(el);

        const line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.25;
        const frame = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
        const padding = parseFloat(style.paddingTop) + parseFloat(style.paddingBottom);
        const ceiling = Math.round(line * maxRows + padding + frame);

        // `auto` на время замера: иначе scrollHeight не умеет уменьшаться —
        // он не бывает меньше уже выставленной высоты, и поле,
        // раз выросши, назад не садится.
        el.style.height = 'auto';

        // scrollHeight считает содержимое с внутренними отступами, но без
        // рамки, а высота у поля меряется по border-box (`box-sizing`
        // из препролога Tailwind) — рамку добавляем руками.
        const wanted = el.scrollHeight + frame;

        el.style.height = Math.min(wanted, ceiling) + 'px';
        el.style.overflowY = wanted > ceiling ? 'auto' : 'hidden';
    },
});
