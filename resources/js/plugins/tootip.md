# Tooltip (Alpine + Tippy)

Плагин `x-tooltip` добавляет единый способ показывать всплывающие подсказки через Alpine.js с помощью `tippy.js`. Поддерживает «умные» триггеры для тача (`.smart`) и кастомную тему `ks`. Также умеет **включать/выключать тултип по брейкпоинтам** (например, только `< xl`).

## Как работает

* Директива: `x-tooltip` (значение — любое Alpine-выражение; HTML разрешён).
* По умолчанию (без `.smart`): подсказка открывается на hover/focus; на тач-устройствах ничего не происходит, если не указать `.click`.
* `.smart`: определяет среду без hover (тач/коарсный pointer) и перестраивает поведение под неё.
* Доступность: если элемент не фокусируемый, плагин проставит `tabindex="0"`.
* При открытии на тач-дисплеях плагин вешает `scroll/resize` → закрывает тултип, чтобы он не «висел».
* **Брейкпоинты (`.lt-*`, `.gte-*` и т.п.)**: если условие не выполнено, **tippy-инстанс не создаётся**. При ресайзе через брейкпоинт инстанс создаётся/уничтожается автоматически.

## Модификаторы

### Положение

* `.top` (по умолчанию), `.bottom`, `.left`, `.right`.

### Темы

* `.theme-<имя>` (по умолчанию `ks`, описана в CSS).

### Отступы

* `.offset-<px>` (по умолчанию `8`) — расстояние от таргета.
* `.skid-<px>` — сдвиг по оси, перпендикулярной направлению.

### Поведение

* `.interactive` — оставляет тултип открытым при наведении на контент.

### Триггеры

* **Без `.smart`**: hover/focus либо `.click`.
* **С `.smart`**:

  * на десктопе остаётся hover/focus;
  * на тачах по умолчанию `click`.
  * дополнительные модификаторы:

    * `.press` — «длинный тап» (~350мс) (и далее можно тапом закрывать/открывать),
    * `.hover-only` — жёстко оставляет hover даже на таче,
    * `.mobile-off` — отключить тултип на тачах.

### Брейкпоинт-гейт (Tailwind-совместимо)

Позволяет включать тултип только в заданных диапазонах ширины. Поддерживаются брейкпоинты: `sm` (640), `md` (768), `lg` (1024), `xl` (1280), `2xl` (1536).

* `.lt-<bp>` — только **меньше** брейкпоинта (`< xl` означает `max-width: 1279px`)
* `.lte-<bp>` — меньше или равно
* `.gte-<bp>` — **с брейкпоинта и выше** (`>= xl` означает `min-width: 1280px`)
* `.gt-<bp>` — строго больше

> Важно: при невыполнении условия плагин **не создаёт** tooltip вообще, а не «показывает пустой».

---

## Примеры

```html
<!-- Базовый -->
<button x-data x-tooltip="'Добавить в корзину'">+</button>

<!-- Смарт-режим: клик на таче, hover на десктопе -->
<a x-data x-tooltip.smart.bottom.offset-10="'Скачать PDF'">Скачать</a>

<!-- Длинный тап + интерактивный контент -->
<div
    x-data
    x-tooltip.smart.press.interactive.theme-ks.offset-12.skid-16="'<b>Бонус:</b> 10% скидка'"
>
    ?
</div>

<!-- Тултип только на экранах МЕНЬШЕ xl (например, если на xl+ есть видимый текст) -->
<div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Войти'">
    ...
</div>

<!-- Тултип только на xl и выше -->
<div x-data x-tooltip.gte-xl="'Подсказка для десктопа'">
    ...
</div>
```

---

## Стили

Подключены в `resources/css/app.css`:

```css
@import "tippy.js/dist/tippy.css";
@import "tippy.js/animations/shift-away-subtle.css";

:root { --ks-tooltip-bg: rgba(22, 44, 77, 0.9); }

/* тема */
.tippy-box[data-theme~="ks"] {
    background-color: var(--ks-tooltip-bg); /* #162c4d с 80% прозрачностью */
    @apply text-white rounded-lg shadow-lg;
}

.tippy-box[data-theme~="ks"] .tippy-content {
    @apply px-3 py-2 text-sm;
}

/* цвет стрелки через ::before */
.tippy-box[data-theme~="ks"][data-placement^="top"] > .tippy-arrow::before {
    border-top-color: var(--ks-tooltip-bg);
}
.tippy-box[data-theme~="ks"][data-placement^="bottom"] > .tippy-arrow::before {
    border-bottom-color: var(--ks-tooltip-bg);
}
.tippy-box[data-theme~="ks"][data-placement^="left"] > .tippy-arrow::before {
    border-left-color: var(--ks-tooltip-bg);
}
.tippy-box[data-theme~="ks"][data-placement^="right"] > .tippy-arrow::before {
    border-right-color: var(--ks-tooltip-bg);
}
```

Можно завести свою тему: `.tippy-box[data-theme~="foo"] { … }` и использовать модификатор `.theme-foo`.

---

## Перенос в другой проект

1. **Зависимости**
   Убедитесь, что есть Alpine.js и установите tippy.js:

```bash
npm i tippy.js
```

2. **Перенесите файл**
   Скопируйте `resources/js/plugins/tooltip.js` (можно в любую директорию плагинов проекта).

3. **Зарегистрируйте плагин в Alpine**

```js
import Alpine from 'alpinejs'
import tooltip from './plugins/tooltip'

window.Alpine = Alpine
Alpine.plugin(tooltip)
Alpine.start()
```

4. **Добавьте стили**
   Импортируйте базовые стили tippy и анимацию, затем пропишите тему (`ks` или свою) в главном CSS/SCSS.

5. **Соберите фронтенд**
   Запустите сборку (`npm run dev` / `npm run build`) и убедитесь, что CSS и JS подключены.

6. **Используйте директиву**
   Расставьте `x-tooltip` в шаблонах. Для мобильной поддержки — `.smart`.
   Чтобы включать тултип только на нужных размерах — используйте `.lt-xl`, `.gte-lg` и т.п.

Если нужен другой набор дефолтных опций (тема, задержки, maxWidth и т.п.), правьте объект опций в `resources/js/plugins/tooltip.js`.
