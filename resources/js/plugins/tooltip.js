import tippy from 'tippy.js'

const BREAKPOINTS = {
    sm: 640,
    md: 768,
    lg: 1024,
    xl: 1280,
    '2xl': 1536,
}

function pickBpRule(modifiers) {
    // lt-xl | lte-xl | gt-xl | gte-xl
    const rule = modifiers.find(m => /^(lt|lte|gt|gte)-(sm|md|lg|xl|2xl)$/.test(m))
    if (!rule) return null

    const [, op, bp] = rule.match(/^(lt|lte|gt|gte)-(sm|md|lg|xl|2xl)$/)
    const px = BREAKPOINTS[bp]

    // Tailwind: xl = min-width: 1280px
    // "lt-xl" значит max-width: 1279px
    if (op === 'lt') return window.matchMedia(`(max-width: ${px - 1}px)`)
    if (op === 'lte') return window.matchMedia(`(max-width: ${px}px)`)
    if (op === 'gt') return window.matchMedia(`(min-width: ${px + 1}px)`)
    if (op === 'gte') return window.matchMedia(`(min-width: ${px}px)`)

    return null
}

export default function (Alpine) {
    Alpine.directive('tooltip', (el, { expression, modifiers }, { evaluateLater, effect, cleanup }) => {
        const getContent = evaluateLater(expression || "''")

        // === Совместимость / smart ===
        const smart = modifiers.includes('smart')

        const isTouchEnv = smart
            ? (window.matchMedia('(hover: none), (pointer: coarse)').matches || (navigator.maxTouchPoints || 0) > 0)
            : false

        const placementAttr = el.getAttribute('data-tooltip-placement')?.trim()
        const placement = placementAttr || ['top', 'bottom', 'left', 'right'].find(m => modifiers.includes(m)) || 'top'
        const interactive = modifiers.includes('interactive')
        const theme = (modifiers.find(m => m.startsWith('theme-'))?.slice(6)) || 'ks'

        const skidMod = modifiers.find(m => m.startsWith('skid-'))
        const distMod = modifiers.find(m => m.startsWith('offset-'))
        const skidding = skidMod ? Number(skidMod.replace('skid-', '')) : 0
        const distance = distMod ? Number(distMod.replace('offset-', '')) : 8

        const mobileOff = smart && modifiers.includes('mobile-off') && isTouchEnv
        const pressMode = smart && modifiers.includes('press') && isTouchEnv
        const forceHover = smart && modifiers.includes('hover-only') && isTouchEnv

        const maxWidthAttr = el.getAttribute('data-tooltip-max-width')?.trim()
        let maxWidth = 260
        if (maxWidthAttr) {
            if (maxWidthAttr === 'none') {
                maxWidth = 'none'
            } else {
                const parsed = Number(maxWidthAttr)
                if (!Number.isNaN(parsed) && parsed > 0) {
                    maxWidth = parsed
                }
            }
        }

        let trigger = modifiers.includes('click') ? 'click' : 'mouseenter focus'
        if (smart && isTouchEnv && !forceHover) {
            trigger = pressMode ? 'manual' : 'click'
        }

        // === NEW: брейкпоинт-гейт ===
        const bpMq = pickBpRule(modifiers) // например, lt-xl => (max-width: 1279px)
        const shouldEnable = () => {
            if (mobileOff) return false
            if (bpMq && !bpMq.matches) return false
            return true
        }

        // === lifecycle tippy ===
        let instance = null
        let lastContent = ''

        // cleanup для press-mode слушателей
        let pressCleanup = () => { }

        const destroyInstance = () => {
            pressCleanup()
            pressCleanup = () => { }

            if (instance) {
                instance.destroy()
                instance = null
            }
        }

        const createInstance = () => {
            if (instance) return

            // на всякий случай: если где-то уже висит _tippy
            if (el._tippy) el._tippy.destroy()

            instance = tippy(el, {
                content: '',
                allowHTML: true,
                arrow: false,
                theme,
                placement,
                interactive,
                trigger,
                appendTo: () => document.body,
                delay: [100, 75],
                animation: 'shift-away-subtle',
                moveTransition: 'transform 0.2s ease-out',
                offset: [skidding, distance],
                hideOnClick: true,
                maxWidth,
                onCreate(inst) {
                    if (!el.hasAttribute('tabindex') && !/^(a|button|input|textarea|select)$/i.test(el.tagName)) {
                        el.setAttribute('tabindex', '0')
                    }
                },
                onShow(inst) {
                    if (!(smart && isTouchEnv)) return
                    const hide = () => inst.hide()
                    const opts = { passive: true }
                    inst._ks_hide = hide
                    window.addEventListener('scroll', hide, opts)
                    window.addEventListener('resize', hide, opts)
                },
                onHide(inst) {
                    if (inst._ks_hide) {
                        window.removeEventListener('scroll', inst._ks_hide)
                        window.removeEventListener('resize', inst._ks_hide)
                        inst._ks_hide = null
                    }
                },
            })

            // актуальный контент (без пустых строк/нуллов как “disable”)
            instance.setContent(lastContent)

            // Долгий тап для .smart.press
            if (smart && pressMode) {
                let pressTimer = null

                const start = () => {
                    clearTimeout(pressTimer)
                    pressTimer = setTimeout(() => instance?.show(), 350)
                }
                const cancel = () => { clearTimeout(pressTimer); pressTimer = null }

                const docStart = (e) => {
                    if (!el.contains(e.target)) instance?.hide()
                }

                const click = () => {
                    if (!instance) return
                    instance.state.isVisible ? instance.hide() : instance.show()
                }

                el.addEventListener('touchstart', start, { passive: true })
                el.addEventListener('touchend', cancel, { passive: true })
                el.addEventListener('touchcancel', cancel, { passive: true })
                document.addEventListener('touchstart', docStart, { passive: true })
                el.addEventListener('click', click)

                pressCleanup = () => {
                    clearTimeout(pressTimer)
                    el.removeEventListener('touchstart', start)
                    el.removeEventListener('touchend', cancel)
                    el.removeEventListener('touchcancel', cancel)
                    document.removeEventListener('touchstart', docStart)
                    el.removeEventListener('click', click)
                }
            }
        }

        const sync = () => {
            if (shouldEnable()) createInstance()
            else destroyInstance()
        }

        // реактивное обновление контента (только если instance существует)
        effect(() => {
            getContent(c => {
                lastContent = (c == null ? '' : String(c))
                if (instance) instance.setContent(lastContent)
            })
        })

        // следим за переходом через брейкпоинт
        let bpListener = null
        if (bpMq) {
            bpListener = () => sync()
            if (bpMq.addEventListener) bpMq.addEventListener('change', bpListener)
            else bpMq.addListener(bpListener)
        }

        // первичная инициализация
        sync()

        cleanup(() => {
            if (bpMq && bpListener) {
                if (bpMq.removeEventListener) bpMq.removeEventListener('change', bpListener)
                else bpMq.removeListener(bpListener)
            }
            destroyInstance()
        })
    })
}
