/*
 * Движок маскота — форк `dev-scripts/bot-image/robot.js` под живую страницу.
 *
 * Демка была самозапускающимся IIFE: она искала `#robot-head` по документу
 * на DOMContentLoaded и, не найдя, замолкала навсегда. Нам это не подходит
 * буквально: маскот приезжает лениво, через несколько секунд после загрузки,
 * когда DOMContentLoaded давно прошёл.
 *
 * Что ещё изменилось против демки и почему:
 *   - узлы ищутся внутри переданного svg, а не по всему документу. Отдельно
 *     стоит отметить тень: демка брала `document.querySelector('.robot-shadow')`,
 *     то есть на чужой странице могла схватить первый попавшийся элемент
 *     с таким классом;
 *   - никакого `window.robotMascot` и никаких классов на <body>: модуль
 *     не имеет права трогать разметку витрины, и второй экземпляр должен
 *     быть возможен;
 *   - амплитуды пересчитываются под реальный размер (см. buildConfig);
 *   - все слушатели складываются в список и снимаются в destroy();
 *   - добавлены nudge() и setAlert() — реакция на пришедший ответ.
 *
 * Вся физика, тайминги и обработка геометрии — из демки без изменений:
 *   один rAF-цикл, фиксированный подшаг 8 мс, единственное чтение layout
 *   за кадр и только по инвалидации, запись исключительно в transform.
 */

/* Размер сцены, под который подбирались амплитуды в демке. */
const BASE_SIZE = 460;

const TAU = Math.PI * 2;

function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

/* Плавное насыщение: линейно у нуля, асимптота на краях. */
function soften(n) { return Math.tanh(n * 1.15); }

function easeInQuad(t) { return t * t; }
function easeOutCubic(t) { const u = 1 - t; return 1 - u * u * u; }

function springStep(s, target, k, d, dt) {
    const a = (target - s.value) * k - s.velocity * d;
    s.velocity += a * dt;
    s.value += s.velocity * dt;
}

function makeSpring(v) { return { value: v, velocity: 0 }; }

function isPlainObject(v) {
    return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function deepMerge(base, patch) {
    const out = { ...base };
    for (const key of Object.keys(patch ?? {})) {
        out[key] = isPlainObject(patch[key]) && isPlainObject(base[key])
            ? deepMerge(base[key], patch[key])
            : patch[key];
    }
    return out;
}

/* Базовые значения — ровно те, что подобраны в демке на сцене 460 px. */
function defaults() {
    return {
        idle: {
            floatAmplitude: 6.0, floatPeriod: 5.4,
            floatAmplitude2: 1.8, floatPeriod2: 3.1,
            rotateAmplitude: 0.75, rotatePeriod: 8.9,
            driftAmplitude: 2.2, driftPeriod: 13.7,
        },
        eyes: {
            reachX: 2.4, reachY: 2.0,
            // Дальность в пикселях. Задана — побеждает reachX/reachY.
            // Нужна для маленького маскота: см. buildConfig.
            reachPxX: null, reachPxY: null,
            travelX: 26.0, travelY: 17.0,
            edgeLimit: 0.88,
            stiffness: 120.0, damping: 17.0,
            hoverGain: 1.18, desync: 0.06,
            recentreOnLeave: true,
            enabled: true,
        },
        blink: {
            minDelay: 2.4, maxDelay: 7.8, delayBias: 1.7,
            closeDuration: 0.085, holdDuration: 0.045, openDuration: 0.165,
            lidScale: 1.045, lidOvershoot: 0.02,
            doubleChance: 0.20, doubleGap: 0.115,
            eyeOffset: 0.012, hoverBlinkDelay: 0.20,
        },
        antenna: {
            pivotX: 592.0, pivotY: 344.0,
            followRotation: -1.45, followVelocity: -0.055,
            stiffness: 85.0, damping: 8.2,
            maxAngle: 4.2, hoverPerk: -2.6,
        },
        hover: {
            stiffness: 70.0, damping: 14.0,
            lift: -5.0, scale: 0.018, tiltToPointer: 1.6,
            pupilDilate: 0.075, leaveDebounce: 0.07,
            enabled: true,
        },
        // Одноразовый подскок «мне пришёл ответ»: два затухающих толчка.
        nudge: {
            lift: -14.0, period: 0.45, decay: 0.28, span: 1.2,
            scale: 0.03, alertEvery: 8.0, alertStrength: 0.6,
        },
        /*
         * Фоновый «зов»: тот же подскок, но сам по себе, раз в несколько
         * секунд. Нужен там, где маскот мелкий и одного парения не хватает,
         * чтобы его заметили боковым зрением.
         *
         * Ритмом, а не непрерывной тряской: то, что дёргается всегда,
         * перестают видеть — и раздражает. Пауза между толчками и есть
         * то, что делает сам толчок заметным.
         */
        ambient: { enabled: false, every: 9.0, strength: 0.55 },
        shadow: { enabled: true, scaleRange: 0.11, opacityRange: 0.28 },
        reducedMotion: { respect: true },
        performance: {
            maxFrameDelta: 0.05, physicsStep: 0.008,
            pauseWhenHidden: true, pauseWhenOffscreen: true,
        },
        eyeGeometry: {
            left: { cx: 418.59, cy: 730.65, rx: 158.68, ry: 165.56 },
            right: { cx: 830.12, cy: 723.20, rx: 162.48, ry: 162.88 },
        },
    };
}

/*
 * Конфиг под конкретный размер.
 *
 * Амплитуды в демке заданы в CSS-пикселях итоговой картинки, а не в долях.
 * На сцене 460 px парение ±6 px — это 1.3 % размера; на кнопке 64 px те же
 * 6 px были бы почти десятью процентами, и робот бился бы о края диска.
 * Поэтому всё, что в пикселях, домножается на size/460.
 *
 * Величины в SVG-юнитах (travelX/Y, пивот антенны, eyeGeometry) масштабируются
 * сами вместе с viewBox — их трогать нельзя. Градусы и доли (rotateAmplitude,
 * hover.scale, tiltToPointer) от размера не зависят по смыслу.
 *
 * Переопределения применяются ПОСЛЕ масштабирования и задаются в конечных
 * пикселях: «lift: -3» значит три пикселя на экране, а не три до пересчёта.
 */
export function buildConfig(size, overrides) {
    const cfg = defaults();
    const k = (size || BASE_SIZE) / BASE_SIZE;

    cfg.idle.floatAmplitude *= k;
    cfg.idle.floatAmplitude2 *= k;
    cfg.idle.driftAmplitude *= k;
    cfg.hover.lift *= k;
    cfg.nudge.lift *= k;

    return deepMerge(cfg, overrides);
}

function measureEye(svg, clipId, fallback) {
    try {
        const p = svg.querySelector('#' + clipId + ' path');
        if (p && typeof p.getBBox === 'function') {
            const b = p.getBBox();
            if (b && b.width > 1 && b.height > 1) {
                return {
                    cx: b.x + b.width / 2, cy: b.y + b.height / 2,
                    rx: b.width / 2, ry: b.height / 2,
                };
            }
        }
    } catch (err) {
        // Firefox отказывает в getBBox на неотрисованных defs — берём фолбэк.
    }
    return { ...fallback };
}

function readCircleCentre(node, fx, fy) {
    if (!node) return { x: fx, y: fy };
    const x = parseFloat(node.getAttribute('cx'));
    const y = parseFloat(node.getAttribute('cy'));
    return { x: Number.isFinite(x) ? x : fx, y: Number.isFinite(y) ? y : fy };
}

function normRadius(pt, g) {
    const u = (pt.x - g.cx) / g.rx;
    const v = (pt.y - g.cy) / g.ry;
    return Math.sqrt(u * u + v * v);
}

/**
 * Оживить уже вставленный в DOM артворк.
 *
 * @param {SVGSVGElement} svg корень артворка
 * @param {object} [options]
 * @param {Element} [options.frame] элемент, по которому меряется позиция;
 *        по умолчанию родитель svg. Важно, что не сам svg: его же и двигают,
 *        и собственная коробка вернула бы парение обратно в расчёт взгляда.
 * @param {number} [options.size] отрисованный размер в CSS-пикселях
 * @param {string} [options.idPrefix] префикс id внутри артворка
 * @param {boolean} [options.fixed] элемент в position:fixed
 * @param {Element|null} [options.shadow] контактная тень, если она есть
 * @param {object} [options.config] точечные переопределения конфига
 * @returns {{blink: Function, nudge: Function, setAlert: Function,
 *            pause: Function, resume: Function, destroy: Function}}
 */
export function mountMascot(svg, options = {}) {
    const P = options.idPrefix ?? 'km-';
    const CONFIG = buildConfig(options.size, options.config);
    const pick = (id) => svg.querySelector('#' + P + id);

    const el = {
        svg,
        antenna: pick('antenna'),
        lidLeft: pick('lid-left'),
        lidRight: pick('lid-right'),
        lidClipL: svg.querySelector('#' + P + 'lid-clip-left path'),
        lidClipR: svg.querySelector('#' + P + 'lid-clip-right path'),
        pupilLeft: pick('pupil-left'),
        pupilRight: pick('pupil-right'),
        shadow: options.shadow ?? null,
        frame: options.frame ?? svg.parentElement ?? svg,
    };

    const geom = {
        left: measureEye(svg, P + 'eye-clip-left-mask', CONFIG.eyeGeometry.left),
        right: measureEye(svg, P + 'eye-clip-right-mask', CONFIG.eyeGeometry.right),
    };

    const rest = {
        left: readCircleCentre(el.pupilLeft, 528.14, 804.35),
        right: readCircleCentre(el.pupilRight, 722.94, 804.35),
    };

    // Клэмп не имеет права утащить зрачок внутрь от авторской позы.
    const edge = {
        left: Math.max(CONFIG.eyes.edgeLimit, normRadius(rest.left, geom.left) + 0.005),
        right: Math.max(CONFIG.eyes.edgeLimit, normRadius(rest.right, geom.right) + 0.005),
    };

    const mq = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

    const state = {
        running: false, rafId: 0, last: 0, clock: 0, accum: 0,
        visible: true, onscreen: true, destroyed: false,
        pointer: { x: 0, y: 0, inside: false, seen: false },
        rect: null, rectDirty: true,
        hover: makeSpring(0), hovering: false, leaveTimer: 0,
        pupil: {
            left: { x: makeSpring(0), y: makeSpring(0) },
            right: { x: makeSpring(0), y: makeSpring(0) },
        },
        antenna: makeSpring(0), lastHeadY: 0, headVel: 0,
        blink: { active: false, t: 0, duration: 0, nextAt: 0, queued: 0 },
        // Огибающая подскока живёт внутри кадра: ни таймеров, ни слушателей.
        nudge: { t: Infinity, gain: 0 },
        alert: false, alertNextAt: 0, ambientNextAt: 0,
    };

    state.ambientNextAt = CONFIG.ambient.every;

    /* ---------------- слушатели ----------------
       Всё, что навешано, складывается сюда и снимается в destroy(). */

    const teardown = [];

    function on(target, type, fn, opts) {
        target.addEventListener(type, fn, opts);
        teardown.push(() => target.removeEventListener(type, fn, opts));
    }

    function onPointerMove(e) {
        state.pointer.x = e.clientX;
        state.pointer.y = e.clientY;
        state.pointer.inside = true;
        state.pointer.seen = true;
    }

    function onPointerLeaveWindow() {
        if (CONFIG.eyes.recentreOnLeave) state.pointer.inside = false;
    }

    function invalidateRect() { state.rectDirty = true; }

    if (CONFIG.eyes.enabled) {
        on(window, 'pointermove', onPointerMove, { passive: true });
        on(document, 'pointerleave', onPointerLeaveWindow, { passive: true });
        on(window, 'blur', onPointerLeaveWindow);
    }

    on(window, 'resize', invalidateRect, { passive: true });

    /*
     * У элемента в position:fixed коробка от прокрутки не меняется вовсе,
     * поэтому слушателя скролла в capture-фазе — а он на витрине со свайперами
     * срабатывает часто — вешать незачем. ResizeObserver там же не нужен:
     * размер кнопки задан в классах и меняется только с окном.
     */
    if (!options.fixed) {
        on(window, 'scroll', invalidateRect, { passive: true, capture: true });
        if (typeof ResizeObserver === 'function') {
            const ro = new ResizeObserver(invalidateRect);
            ro.observe(el.frame);
            teardown.push(() => ro.disconnect());
        }
    }

    if (CONFIG.hover.enabled) {
        // Ховер ловится только на закрашенной геометрии: прозрачный холст
        // вокруг головы за «заметил» не считается.
        on(svg, 'pointerover', (e) => {
            if (e.target === svg) return;
            if (state.leaveTimer) {
                clearTimeout(state.leaveTimer);
                state.leaveTimer = 0;
            }
            if (!state.hovering) {
                state.hovering = true;
                scheduleBlink(CONFIG.blink.hoverBlinkDelay);
            }
        });

        on(svg, 'pointerout', () => {
            if (state.leaveTimer) clearTimeout(state.leaveTimer);
            // Пересечение внутренних фигур даёт очередь out/over — гасим дребезг.
            state.leaveTimer = setTimeout(() => {
                state.leaveTimer = 0;
                state.hovering = false;
            }, CONFIG.hover.leaveDebounce * 1000);
        });
    }

    if (CONFIG.performance.pauseWhenHidden) {
        on(document, 'visibilitychange', () => {
            state.visible = !document.hidden;
            syncRunning();
        });
    }

    if (CONFIG.performance.pauseWhenOffscreen && typeof IntersectionObserver === 'function') {
        const io = new IntersectionObserver((entries) => {
            state.onscreen = entries[0].isIntersecting;
            syncRunning();
        }, { threshold: 0 });
        io.observe(el.frame);
        teardown.push(() => io.disconnect());
    }

    if (mq) {
        if (mq.addEventListener) {
            on(mq, 'change', applyMotionPreference);
        } else if (mq.addListener) {
            mq.addListener(applyMotionPreference);
            teardown.push(() => mq.removeListener(applyMotionPreference));
        }
    }

    /* ---------------- моргание ---------------- */

    function randomDelay() {
        const b = CONFIG.blink;
        const r = Math.pow(Math.random(), b.delayBias);
        return b.minDelay + r * (b.maxDelay - b.minDelay);
    }

    function scheduleBlink(delay) { state.blink.nextAt = state.clock + delay; }

    function startBlink() {
        const b = CONFIG.blink;
        state.blink.active = true;
        state.blink.t = 0;
        state.blink.duration = b.closeDuration + b.holdDuration + b.openDuration;
    }

    function lidAt(t) {
        const b = CONFIG.blink;
        if (t < 0) return 0;
        if (t < b.closeDuration) return easeInQuad(t / b.closeDuration);
        t -= b.closeDuration;
        if (t < b.holdDuration) return 1;
        t -= b.holdDuration;
        if (t < b.openDuration) return 1 - easeOutCubic(t / b.openDuration);
        return 0;
    }

    /* ---------------- кадр ---------------- */

    function frame(now) {
        state.rafId = requestAnimationFrame(frame);

        let dt = (now - state.last) / 1000;
        state.last = now;
        if (!Number.isFinite(dt) || dt <= 0) return;
        dt = Math.min(dt, CONFIG.performance.maxFrameDelta);
        state.clock += dt;

        // READ: единственное чтение layout за кадр, и только по инвалидации.
        if (state.rectDirty || !state.rect) {
            state.rect = el.frame.getBoundingClientRect();
            state.rectDirty = false;
        }
        const rect = state.rect;
        const halfW = rect.width * 0.5 || 1;
        const halfH = rect.height * 0.5 || 1;
        const cx = rect.left + halfW;
        const cy = rect.top + halfH;

        const I = CONFIG.idle;
        const headY = Math.sin(state.clock * TAU / I.floatPeriod) * I.floatAmplitude
            + Math.sin(state.clock * TAU / I.floatPeriod2 + 1.7) * I.floatAmplitude2;
        const headX = Math.sin(state.clock * TAU / I.driftPeriod + 0.6) * I.driftAmplitude;
        const headRot = Math.sin(state.clock * TAU / I.rotatePeriod + 2.3) * I.rotateAmplitude;

        state.headVel = (headY - state.lastHeadY) / dt;
        state.lastHeadY = headY;

        const E = CONFIG.eyes;
        let nx = 0;
        let ny = 0;
        if (E.enabled && state.pointer.seen && state.pointer.inside) {
            // reachPx — дальность в пикселях экрана. Без неё дальность считается
            // в полуширинах головы, и на маленьком маскоте зрачки упираются
            // в край, стоит курсору отойти на ширину пальца.
            const reachX = E.reachPxX ?? (halfW * E.reachX);
            const reachY = E.reachPxY ?? (halfH * E.reachY);
            nx = soften((state.pointer.x - cx) / reachX);
            ny = soften((state.pointer.y - cy) / reachY);
        }

        const H = CONFIG.hover;
        const A = CONFIG.antenna;
        const hoverTarget = state.hovering ? 1 : 0;
        const gaze = 1 + (E.hoverGain - 1) * state.hover.value;

        const antennaTarget = clamp(
            headRot * A.followRotation
            + state.headVel * A.followVelocity
            + A.hoverPerk * state.hover.value,
            -A.maxAngle, A.maxAngle,
        );

        state.accum += dt;
        const step = CONFIG.performance.physicsStep;
        let guard = 0;
        while (state.accum >= step && guard++ < 64) {
            springStep(state.hover, hoverTarget, H.stiffness, H.damping, step);
            springStep(state.antenna, antennaTarget, A.stiffness, A.damping, step);
            springStep(state.pupil.left.x, nx * E.travelX * gaze, E.stiffness, E.damping, step);
            springStep(state.pupil.left.y, ny * E.travelY * gaze, E.stiffness, E.damping, step);
            springStep(state.pupil.right.x, nx * E.travelX * gaze * (1 + E.desync),
                E.stiffness * (1 - E.desync), E.damping, step);
            springStep(state.pupil.right.y, ny * E.travelY * gaze * (1 - E.desync),
                E.stiffness * (1 - E.desync), E.damping, step);
            state.accum -= step;
        }

        const B = CONFIG.blink;
        if (state.blink.active) {
            state.blink.t += dt;
            if (state.blink.t >= state.blink.duration) {
                state.blink.active = false;
                if (state.blink.queued > 0) {
                    state.blink.queued--;
                    scheduleBlink(B.doubleGap);
                } else {
                    scheduleBlink(randomDelay());
                }
            }
        } else if (state.clock >= state.blink.nextAt) {
            if (Math.random() < B.doubleChance) state.blink.queued = 1;
            startBlink();
        }

        const lidL = state.blink.active ? lidAt(state.blink.t) : 0;
        const lidR = state.blink.active ? lidAt(state.blink.t - B.eyeOffset) : 0;

        // Подскок: два затухающих толчка, огибающая считается здесь же.
        const N = CONFIG.nudge;
        let nudgeLift = 0;
        let nudgeScale = 0;
        if (state.nudge.t < N.span) {
            state.nudge.t += dt;
            const env = Math.exp(-state.nudge.t / N.decay) * state.nudge.gain;
            const wave = Math.sin(TAU * state.nudge.t / N.period);
            nudgeLift = N.lift * wave * env;
            nudgeScale = N.scale * wave * env;
        }
        if (state.alert && state.clock >= state.alertNextAt) {
            state.alertNextAt = state.clock + N.alertEvery;
            nudge(N.alertStrength);
        } else if (!state.alert && CONFIG.ambient.enabled
            && state.clock >= state.ambientNextAt) {
            // Без моргания: фоновый зов должен читаться как «шевельнулся»,
            // а не как «уставился». Моргание приберегаем для ответа.
            state.ambientNextAt = state.clock + CONFIG.ambient.every;
            nudge(CONFIG.ambient.strength, false);
        }

        // WRITE: только transform, ни одного свойства, влияющего на layout.
        const hv = state.hover.value;
        const tiltTotal = headRot + nx * H.tiltToPointer * hv;
        const lift = headY + H.lift * hv + nudgeLift;
        const scale = 1 + H.scale * hv + nudgeScale;

        svg.style.transform = 'translate3d(' + headX.toFixed(3) + 'px,'
            + lift.toFixed(3) + 'px,0) rotate(' + tiltTotal.toFixed(4)
            + 'deg) scale(' + scale.toFixed(5) + ')';

        if (el.antenna) {
            el.antenna.setAttribute('transform',
                'rotate(' + state.antenna.value.toFixed(4) + ' ' + A.pivotX + ' ' + A.pivotY + ')');
        }

        writeLid(el.lidLeft, geom.left, lidL);
        writeLid(el.lidRight, geom.right, lidR);

        const dilate = 1 + H.pupilDilate * hv;
        writePupil(el.pupilLeft, state.pupil.left, rest.left, geom.left, edge.left, dilate);
        writePupil(el.pupilRight, state.pupil.right, rest.right, geom.right, edge.right, dilate);

        if (el.shadow && CONFIG.shadow.enabled) {
            const rise = headY / (I.floatAmplitude + I.floatAmplitude2 || 1);
            const s = 1 - rise * CONFIG.shadow.scaleRange - hv * 0.05;
            el.shadow.style.transform = 'scale(' + s.toFixed(4) + ')';
            el.shadow.style.opacity = (1 - rise * CONFIG.shadow.opacityRange - hv * 0.08).toFixed(4);
        }
    }

    /* ---------------- геометрия век и зрачков ---------------- */

    function lidScaleMatrix(g, k) {
        return 'translate(' + g.cx + ' ' + g.cy + ') scale(' + k + ') '
            + 'translate(' + (-g.cx) + ' ' + (-g.cy) + ')';
    }

    function scaleAboutEye(node, g) {
        if (!node) return;
        node.setAttribute('transform', lidScaleMatrix(g, CONFIG.blink.lidScale));
    }

    // Веко — силуэт самого глаза, припаркованный выше и съезжающий в клип.
    function writeLid(node, g, lid) {
        if (!node) return;
        const k = CONFIG.blink.lidScale;
        const travel = g.ry * 2 * k * (1 + CONFIG.blink.lidOvershoot);
        const y = -travel * (1 - lid);
        node.setAttribute('transform',
            'translate(0 ' + y.toFixed(2) + ') ' + lidScaleMatrix(g, k));
    }

    function writePupil(node, spr, restPt, g, edgeLimit, dilate) {
        if (!node) return;
        let dx = spr.x.value;
        let dy = spr.y.value;

        let px = restPt.x + dx;
        let py = restPt.y + dy;
        const u = (px - g.cx) / g.rx;
        const v = (py - g.cy) / g.ry;
        const r = Math.sqrt(u * u + v * v);
        if (r > edgeLimit && r > 0) {
            const k = edgeLimit / r;
            px = g.cx + u * k * g.rx;
            py = g.cy + v * k * g.ry;
            dx = px - restPt.x;
            dy = py - restPt.y;
        }

        let t = 'translate(' + dx.toFixed(3) + ' ' + dy.toFixed(3) + ')';
        if (dilate && Math.abs(dilate - 1) > 1e-4) {
            t += ' translate(' + restPt.x + ' ' + restPt.y + ')'
                + ' scale(' + dilate.toFixed(4) + ')'
                + ' translate(' + (-restPt.x) + ' ' + (-restPt.y) + ')';
        }
        node.setAttribute('transform', t);
    }

    /* ---------------- управление циклом ---------------- */

    function isReduced() {
        return CONFIG.reducedMotion.respect && mq !== null && mq.matches;
    }

    function syncRunning() {
        if (state.destroyed) return;
        const should = state.visible && state.onscreen && !isReduced();
        if (should && !state.running) {
            state.running = true;
            state.last = performance.now();
            state.accum = 0;
            state.rectDirty = true;
            state.rafId = requestAnimationFrame(frame);
        } else if (!should && state.running) {
            state.running = false;
            cancelAnimationFrame(state.rafId);
        }
    }

    function restPose() {
        svg.style.transform = '';
        if (el.antenna) el.antenna.removeAttribute('transform');
        // Веки паркуются выше глаза: их поза не снимается, а восстанавливается.
        writeLid(el.lidLeft, geom.left, 0);
        writeLid(el.lidRight, geom.right, 0);
        for (const n of [el.pupilLeft, el.pupilRight]) {
            if (n) n.removeAttribute('transform');
        }
        if (el.shadow) {
            el.shadow.style.transform = '';
            el.shadow.style.opacity = '';
        }
    }

    function applyMotionPreference() {
        if (isReduced()) {
            state.running = false;
            cancelAnimationFrame(state.rafId);
            restPose();
        } else {
            syncRunning();
        }
    }

    function nudge(strength = 1, withBlink = true) {
        state.nudge = { t: 0, gain: strength };
        if (!withBlink) return;
        if (!state.blink.active) startBlink();
        state.blink.queued = 1;
    }

    /* ---------------- старт ---------------- */

    scaleAboutEye(el.lidClipL, geom.left);
    scaleAboutEye(el.lidClipR, geom.right);
    writeLid(el.lidLeft, geom.left, 0);
    writeLid(el.lidRight, geom.right, 0);

    scheduleBlink(randomDelay() * 0.5);
    applyMotionPreference();

    return {
        blink() { if (!state.blink.active) startBlink(); },
        nudge,
        setAlert(on) {
            state.alert = Boolean(on);
            // Первый толчок сразу, дальше по alertEvery из кадра.
            if (state.alert) {
                state.alertNextAt = state.clock + CONFIG.nudge.alertEvery;
                nudge(1);
            }
        },
        pause() { state.visible = false; syncRunning(); },
        resume() { state.visible = true; syncRunning(); },
        destroy() {
            state.destroyed = true;
            state.running = false;
            cancelAnimationFrame(state.rafId);
            if (state.leaveTimer) clearTimeout(state.leaveTimer);
            for (const off of teardown) off();
            teardown.length = 0;
            restPose();
        },
    };
}
