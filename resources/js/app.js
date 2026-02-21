import tooltip from './plugins/tooltip';
import Swiper from 'swiper';
import { A11y, Autoplay, FreeMode, Mousewheel, Navigation, Pagination, Thumbs } from 'swiper/modules';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';
import noUiSlider from 'nouislider';

const pswpPadding = (viewportSize) => {
    const pad = viewportSize.x < 768 ? 12 : 24;

    return {
        top: pad,
        bottom: pad,
        left: pad,
        right: pad,
    };
};

const pickLargestSrcsetUrl = (srcset) => {
    if (!srcset) {
        return null;
    }

    let bestUrl = null;
    let bestWidth = 0;

    srcset.split(',').forEach((entry) => {
        const [url, size] = entry.trim().split(/\s+/);

        if (!url || !size) {
            return;
        }

        const match = size.match(/^(\d+)w$/);
        if (!match) {
            return;
        }

        const width = Number(match[1]);
        if (width > bestWidth) {
            bestWidth = width;
            bestUrl = url;
        }
    });

    return bestUrl;
};

const ensurePswpSizes = (galleryEl, selector = 'a') => {
    const jobs = [];

    galleryEl.querySelectorAll(selector).forEach((anchor) => {
        if (anchor.hasAttribute('data-pswp-width')) {
            return;
        }

        const srcset = anchor.getAttribute('data-pswp-srcset');
        const url = pickLargestSrcsetUrl(srcset) || anchor.getAttribute('href');

        if (!url) {
            return;
        }

        jobs.push(new Promise((resolve) => {
            const img = new Image();

            img.onload = () => {
                anchor.dataset.pswpWidth = img.naturalWidth;
                anchor.dataset.pswpHeight = img.naturalHeight;
                resolve();
            };
            img.onerror = resolve;
            img.src = url;
        }));
    });

    return Promise.all(jobs);
};

const initImageGalleries = () => {
    document.querySelectorAll('[data-image-gallery]').forEach((gallery) => {
        if (gallery.dataset.imageGalleryInitialized === 'true') {
            return;
        }

        const mainEl = gallery.querySelector('[data-image-gallery-main]');
        const thumbsEl = gallery.querySelector('[data-image-gallery-thumbs]');

        if (!mainEl || !thumbsEl) {
            return;
        }

        const thumbsDirection = gallery.dataset.imageGalleryThumbsDirection;
        const thumbsOptions = {
            modules: [FreeMode, Thumbs],
            spaceBetween: 10,
            slidesPerView: 4,
            freeMode: true,
            watchSlidesProgress: true,
        };

        if (thumbsDirection === 'vertical') {
            thumbsOptions.breakpoints = {
                0: {
                    direction: 'horizontal',
                    slidesPerView: 4,
                },
                480: {
                    direction: 'vertical',
                    slidesPerView: 4,
                },
            };
        }

        const thumbsSwiper = new Swiper(thumbsEl, thumbsOptions);

        const nextEl = gallery.querySelector('[data-image-gallery-next]');
        const prevEl = gallery.querySelector('[data-image-gallery-prev]');

        const options = {
            modules: [Navigation, Thumbs],
            spaceBetween: 10,
            thumbs: {
                swiper: thumbsSwiper,
            },
        };

        if (nextEl && prevEl) {
            options.navigation = {
                nextEl,
                prevEl,
            };
        }

        new Swiper(mainEl, options);

        gallery.dataset.imageGalleryInitialized = 'true';
    });
};

const initHeroSliders = () => {
    document.querySelectorAll('[data-hero-slider]').forEach((slider) => {
        if (slider.dataset.heroSliderInitialized === 'true') {
            return;
        }

        const nextEl = slider.querySelector('.swiper-button-next');
        const prevEl = slider.querySelector('.swiper-button-prev');

        const options = {
            modules: [Autoplay, Navigation],
            slidesPerView: 1,
            loop: true,
            speed: 600,
            autoplay: {
                delay: 4500,
                disableOnInteraction: false,
                pauseOnMouseEnter: true,
            },
        };

        if (nextEl && prevEl) {
            options.navigation = {
                nextEl,
                prevEl,
            };
        }

        new Swiper(slider, options);

        slider.dataset.heroSliderInitialized = 'true';
    });
};

const initProductCardSwipers = () => {
    document.querySelectorAll('.product-card__swiper').forEach((swiperEl) => {
        if (swiperEl.dataset.productCardSwiperInitialized === 'true') {
            return;
        }

        if (swiperEl.swiper) {
            swiperEl.dataset.productCardSwiperInitialized = 'true';
            return;
        }

        const slides = swiperEl.querySelectorAll('.swiper-slide');
        if (slides.length <= 1) {
            swiperEl.dataset.productCardSwiperInitialized = 'true';
            return;
        }

        const paginationEl = swiperEl.querySelector('.swiper-pagination');

        const swiper = new Swiper(swiperEl, {
            modules: [Pagination],
            slidesPerView: 1,
            loop: false,
            speed: 0,
            allowTouchMove: false,
            simulateTouch: false,
            breakpoints: {
                0: { allowTouchMove: true, simulateTouch: true },
                1024: { allowTouchMove: false, simulateTouch: false },
            },
            pagination: paginationEl
                ? { el: paginationEl, clickable: true }
                : undefined,
        });

        let rafId = 0;
        let lastIndex = swiper.activeIndex;
        const desktopQuery = window.matchMedia('(min-width: 1024px)');
        const ignoreSelector = '[data-product-card-swiper-ignore]';
        const isIgnoredZone = (event) => {
            const topElement = document.elementFromPoint(event.clientX, event.clientY);

            return topElement instanceof Element && Boolean(topElement.closest(ignoreSelector));
        };

        if (paginationEl) {
            paginationEl.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });
        }

        swiper.on('slideChange', () => {
            lastIndex = swiper.activeIndex;
        });

        const handleMove = (event) => {
            if (!desktopQuery.matches) {
                return;
            }

            if (isIgnoredZone(event)) {
                return;
            }

            const rect = swiperEl.getBoundingClientRect();
            if (!rect.width) {
                return;
            }

            const x = Math.min(Math.max(event.clientX - rect.left, 0), rect.width - 1);
            const total = swiper.slides.length;
            const nextIndex = Math.min(total - 1, Math.floor((x / rect.width) * total));

            if (nextIndex !== lastIndex) {
                swiper.slideTo(nextIndex, 0, false);
                lastIndex = nextIndex;
            }
        };

        swiperEl.addEventListener('mousemove', (event) => {
            if (!desktopQuery.matches) {
                return;
            }

            cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(() => handleMove(event));
        }, { passive: true });

        swiperEl.addEventListener('mouseleave', (event) => {
            if (!desktopQuery.matches || swiper.destroyed) {
                return;
            }

            const relatedTarget = event.relatedTarget;
            if (relatedTarget instanceof Element && relatedTarget.closest(ignoreSelector)) {
                return;
            }

            swiper.slideTo(0, 120, false);
            lastIndex = -1;
        });

        swiperEl.dataset.productCardSwiperInitialized = 'true';
    });
};

// ------------------------------
// noUiSlider для range-фильтров
// ------------------------------
const buildRangeContext = (wrap) => {
    if (!wrap) return null;

    const sliderEl = wrap.querySelector('.js-range-slider');
    const minInput = wrap.querySelector('.js-range-min');
    const maxInput = wrap.querySelector('.js-range-max');

    const metaMin = parseFloat(wrap.dataset.min);
    const metaMax = parseFloat(wrap.dataset.max);
    const step = parseFloat(wrap.dataset.step || '1');
    const key = wrap.dataset.key;
    let sliderStep = step;
    if (
        key === 'price'
        && Number.isFinite(step)
        && Number.isInteger(step)
        && step > 1
        && Number.isFinite(metaMin)
        && Number.isInteger(metaMin)
        && (metaMin % step) !== 0
    ) {
        sliderStep = 1;
    }
    const decimals = (sliderStep.toString().split('.')[1] || '').length;

    const round = (v) => (decimals ? Number(Number(v).toFixed(decimals)) : Math.round(Number(v)));
    const atExtremes = (a, b) => a <= metaMin && b >= metaMax;

    return {
        wrap,
        sliderEl,
        slider: sliderEl?.noUiSlider,
        minInput,
        maxInput,
        metaMin,
        metaMax,
        step,
        sliderStep,
        round,
        atExtremes,
    };
};

const resetRangeSliderState = (ctx) => {
    if (!ctx?.slider) return;

    const { slider, metaMin, metaMax, minInput, maxInput } = ctx;
    slider.set([metaMin, metaMax]);

    if (minInput) minInput.value = '';
    if (maxInput) maxInput.value = '';
};

const initNouisliderOnRange = (root = document) => {
    const findLivewireComponent = (el) => {
        const host = el?.closest?.('[wire\\:id]');
        if (!host || !window.Livewire?.find) return null;
        return window.Livewire.find(host.getAttribute('wire:id'));
    };

    const syncRangeToLivewire = (wrap, minInput, maxInput) => {
        const key = wrap?.dataset?.key;
        const comp = findLivewireComponent(wrap);
        if (!key || !comp) return false;

        const parseVal = (input) => {
            if (!input) return null;
            const raw = input.value;
            if (raw === '' || raw === null || raw === undefined) return null;
            const num = Number(raw);
            return Number.isFinite(num) ? num : null;
        };

        comp.set(`filters.${key}`, {
            min: parseVal(minInput),
            max: parseVal(maxInput),
        });

        return true;
    };

    root.querySelectorAll('[data-range="slider"]').forEach((wrap) => {
        const ctx = buildRangeContext(wrap);
        if (!ctx?.sliderEl) return;

        const {
            sliderEl,
            minInput,
            maxInput,
            metaMin,
            metaMax,
            sliderStep,
            round,
            atExtremes,
        } = ctx;

        if (sliderEl.noUiSlider && typeof sliderEl.noUiSlider.destroy === 'function') {
            sliderEl.noUiSlider.destroy();
        }
        const fireInput = (el) => el && el.dispatchEvent(new Event('input', { bubbles: true }));

        const startMin = minInput?.value !== '' ? parseFloat(minInput.value) : metaMin;
        const startMax = maxInput?.value !== '' ? parseFloat(maxInput.value) : metaMax;

        // Keep custom slider class in case DOM libraries rewrite className during re-init.
        sliderEl.classList.add('ks-range');

        noUiSlider.create(sliderEl, {
            start: [startMin, startMax],
            connect: true,
            step: sliderStep,
            range: { min: metaMin, max: metaMax },
            behaviour: 'tap-drag',
        });

        sliderEl.noUiSlider.on('update', (values) => {
            const [a, b] = values.map(Number).map(round);

            if (minInput) {
                minInput.value = a;
                minInput.dispatchEvent(new Event('pretty:update'));
            }

            if (maxInput) {
                maxInput.value = b;
                maxInput.dispatchEvent(new Event('pretty:update'));
            }
        });

        sliderEl.noUiSlider.on('change', (values) => {
            const [a, b] = values.map(Number).map(round);
            if (atExtremes(a, b)) {
                if (minInput) minInput.value = '';
                if (maxInput) maxInput.value = '';
            } else {
                if (minInput) minInput.value = a;
                if (maxInput) maxInput.value = b;
            }
            const synced = syncRangeToLivewire(wrap, minInput, maxInput);
            if (!synced) {
                fireInput(minInput);
                fireInput(maxInput);
            }
        });

        const commitFromInputs = (source = 'unknown') => {
            const rawMin = parseFloat(minInput?.value);
            const rawMax = parseFloat(maxInput?.value);
            const hasMin = !Number.isNaN(rawMin);
            const hasMax = !Number.isNaN(rawMax);

            if (!hasMin && !hasMax) {
                sliderEl.noUiSlider.set([metaMin, metaMax]);
                if (minInput) minInput.value = '';
                if (maxInput) maxInput.value = '';
                fireInput(minInput);
                fireInput(maxInput);
                return;
            }

            const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
            let a = hasMin ? rawMin : metaMin;
            let b = hasMax ? rawMax : metaMax;
            a = clamp(a, metaMin, metaMax);
            b = clamp(b, metaMin, metaMax);
            if (hasMin && hasMax && a > b) {
                if (source === 'max') {
                    b = a;
                } else if (source === 'min') {
                    a = b;
                } else {
                    a = b;
                }
            }

            sliderEl.noUiSlider.set([round(a), round(b)]);
            if (minInput) minInput.value = atExtremes(a, b) ? '' : round(a);
            if (maxInput) maxInput.value = atExtremes(a, b) ? '' : round(b);
            if (!syncRangeToLivewire(wrap, minInput, maxInput)) {
                fireInput(minInput);
                fireInput(maxInput);
            }
        };

        const getVisibleInput = (input) => {
            if (!input) return null;
            if (!input.classList.contains('hidden')) return input;
            return input.parentElement?.querySelector('[x-ref="visible"]') || null;
        };

        const bindInputHandlers = (input, side, triggerEl = input) => {
            if (!triggerEl) return;
            if (triggerEl.__rangeHandlers) {
                triggerEl.removeEventListener('blur', triggerEl.__rangeHandlers.blur);
                triggerEl.removeEventListener('keydown', triggerEl.__rangeHandlers.keydown);
            }
            const handlers = {
                blur: () => commitFromInputs(side),
                keydown: (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        commitFromInputs(side);
                    }
                },
            };
            triggerEl.__rangeHandlers = handlers;
            triggerEl.addEventListener('blur', handlers.blur);
            triggerEl.addEventListener('keydown', handlers.keydown);
        };

        const minTrigger = getVisibleInput(minInput) || minInput;
        const maxTrigger = getVisibleInput(maxInput) || maxInput;
        bindInputHandlers(minInput, 'min', minTrigger);
        bindInputHandlers(maxInput, 'max', maxTrigger);
    });
};

const resetAllRangeSliders = (root = document) => {
    root.querySelectorAll('[data-range="slider"]').forEach((wrap) => {
        const ctx = buildRangeContext(wrap);
        resetRangeSliderState(ctx);
    });
};

const cssEscape = (s) =>
    (window.CSS && CSS.escape) ? CSS.escape(String(s)) : String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');

const resetOneRangeSlider = (wrap) => {
    const ctx = buildRangeContext(wrap);
    resetRangeSliderState(ctx);
};

const initCompareSwipers = (root = document) => {
    root.querySelectorAll('.compare-swiper').forEach((wrap) => {
        const element = wrap.querySelector('.js-compare-swiper');

        if (!element || element.swiper || element.dataset.initing === '1') {
            return;
        }

        element.dataset.initing = '1';

        let columnsCount = Number(wrap.dataset.colsCount || 0);
        if (!Number.isFinite(columnsCount) || columnsCount <= 0) {
            columnsCount = 1;
        }

        const clamp = (value) => Math.max(1, Math.min(value, columnsCount));

        try {
            new Swiper(element, {
                modules: [A11y, Navigation, Mousewheel],
                slidesPerView: clamp(1),
                loop: columnsCount > 1,
                spaceBetween: 0,
                nested: true,
                mousewheel: { forceToAxis: true },
                resistanceRatio: 0,
                speed: 300,
                a11y: { enabled: true },
                observer: false,
                observeParents: false,
                navigation: {
                    nextEl: wrap.querySelector('.js-compare-next'),
                    prevEl: wrap.querySelector('.js-compare-prev'),
                },
                breakpoints: {
                    480: { slidesPerView: clamp(2) },
                    640: { slidesPerView: clamp(3) },
                    768: { slidesPerView: clamp(2) },
                    1024: { slidesPerView: clamp(3) },
                    1280: { slidesPerView: clamp(4) },
                },
                on: {
                    afterInit: () => {
                        requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
                    },
                },
            });
        } finally {
            delete element.dataset.initing;
        }
    });
};

const destroyCompareSwipers = (root = document) => {
    root.querySelectorAll('.compare-swiper .js-compare-swiper').forEach((element) => {
        if (!element.swiper) {
            return;
        }

        try {
            element.swiper.destroy(true, true);
        } catch {
            //
        }

        try {
            delete element.swiper;
        } catch {
            //
        }
    });
};

const prettyNumberInputFactory = (config = {}) => ({
    decimals: config.decimals ?? 0,
    init() {
        const hidden = this.$refs.hidden;
        if (hidden) {
            const sync = () => this.syncFromHidden();
            hidden.addEventListener('input', sync);
            hidden.addEventListener('change', sync);
            hidden.addEventListener('pretty:update', sync);
        }

        this.syncFromHidden();
    },

    format(value) {
        if (value === null || value === undefined || value === '') return '';

        const num = Number(value);
        if (Number.isNaN(num)) return '';

        return num
            .toLocaleString('ru-RU', {
                minimumFractionDigits: this.decimals,
                maximumFractionDigits: this.decimals,
            })
            .replace(/\s/g, '\u202f');
    },

    parse(str) {
        if (!str) return null;

        const cleaned = String(str)
            .replace(/\s/g, '')
            .replace(/\u202f/g, '')
            .replace(',', '.');

        const num = Number(cleaned);
        return Number.isNaN(num) ? null : num;
    },

    syncFromHidden() {
        const hidden = this.$refs.hidden;
        const visible = this.$refs.visible;
        if (!hidden || !visible) return;

        visible.value = this.format(hidden.value);
    },

    onInput(event) {
        const visible = event.target;
        const hidden = this.$refs.hidden;
        if (!hidden) return;

        const raw = this.parse(visible.value);

        hidden.value = raw ?? '';

        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));

        visible.value = this.format(raw);
    },
});

if (typeof window !== 'undefined') {
    window.prettyNumberInput = prettyNumberInputFactory;
}

let imageGalleryLightboxes = [];
let imageGalleryLightboxVersion = 0;

const initImageGalleryLightbox = () => {
    imageGalleryLightboxVersion += 1;
    const currentVersion = imageGalleryLightboxVersion;

    imageGalleryLightboxes.forEach((lightbox) => lightbox.destroy());
    imageGalleryLightboxes = [];

    const galleries = Array.from(document.querySelectorAll('[data-image-gallery-main]'))
        .filter((gallery) => gallery.querySelector('a'));

    if (!galleries.length) {
        return;
    }

    galleries.forEach((gallery) => {
        const childSelector = gallery.querySelector('a.js-pswp') ? 'a.js-pswp' : 'a';

        ensurePswpSizes(gallery, childSelector).then(() => {
            if (currentVersion !== imageGalleryLightboxVersion) {
                return;
            }

            const lightbox = new PhotoSwipeLightbox({
                gallery,
                children: childSelector,
                pswpModule: () => import('photoswipe'),
                initialZoomLevel: 'fit',
                secondaryZoomLevel: 1,
                maxZoomLevel: 1,
                paddingFn: pswpPadding,
                wheelToZoom: false,
                closeOnVerticalDrag: true,
                zoom: false,
            });

            lightbox.init();
            imageGalleryLightboxes.push(lightbox);
        });
    });
};

document.addEventListener('DOMContentLoaded', initImageGalleries);
document.addEventListener('livewire:navigated', initImageGalleries);
document.addEventListener('DOMContentLoaded', initHeroSliders);
document.addEventListener('livewire:navigated', initHeroSliders);
document.addEventListener('DOMContentLoaded', initImageGalleryLightbox);
document.addEventListener('livewire:navigated', initImageGalleryLightbox);
document.addEventListener('DOMContentLoaded', initProductCardSwipers);
document.addEventListener('livewire:navigated', initProductCardSwipers);
document.addEventListener('DOMContentLoaded', () => initNouisliderOnRange(document));
document.addEventListener('livewire:navigated', () => initNouisliderOnRange(document));
document.addEventListener('DOMContentLoaded', () => initCompareSwipers(document));
document.addEventListener('livewire:navigated', () => initCompareSwipers(document));

let productCardSwiperHooked = false;
document.addEventListener('livewire:initialized', () => {
    if (productCardSwiperHooked) {
        return;
    }

    productCardSwiperHooked = true;

    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.added', () => {
            initProductCardSwipers();
        });
    }
});

document.addEventListener('livewire:init', () => {
    if (!window.Livewire) return;

    Livewire.on('filters-cleared', () => {
        requestAnimationFrame(() => resetAllRangeSliders(document));
    });

    Livewire.on('filter-cleared', (payload) => {
        const key = typeof payload === 'string' ? payload : payload?.key;
        if (!key) return;

        requestAnimationFrame(() => {
            const sel = `[data-range="slider"][data-key="${cssEscape(key)}"]`;
            const wrap = document.querySelector(sel);
            if (wrap) resetOneRangeSlider(wrap);
        });
    });

    Livewire.hook('morph.updated', ({ el }) => {
        const scope = el || document;

        initNouisliderOnRange(scope);

        const compareScope = scope.closest?.('.compare-page') || document.querySelector('.compare-page');
        if (compareScope) {
            initCompareSwipers(compareScope);
            requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
        }
    });

    Livewire.hook('morph.updating', ({ el }) => {
        const scope = el?.closest?.('.compare-page');
        if (scope) {
            destroyCompareSwipers(scope);
        }
    });

    Livewire.on('category:scrollToProducts', () => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const anchor = document.getElementById('category-products-top');
                if (!anchor) return;

                const header = document.querySelector('header');
                const headerHeight = header ? header.getBoundingClientRect().height : 0;
                const targetY = window.scrollY + anchor.getBoundingClientRect().top - headerHeight - 12;

                if (window.scrollY <= targetY + 4) {
                    return;
                }

                window.scrollTo({
                    top: Math.max(0, targetY),
                    behavior: 'smooth',
                });
            });
        });
    });
});


const navDropdownFactory = () => ({
    open: false,
    t: null,
    show() {
        clearTimeout(this.t);
        this.open = true;
    },
    hide(delay = 150) {
        clearTimeout(this.t);
        this.t = setTimeout(() => this.open = false, delay);
    },
    toggle() {
        clearTimeout(this.t);
        this.open = !this.open;
    },
    close() {
        clearTimeout(this.t);
        this.open = false;
    },
});

const catalogMenuFactory = (initialRootId = null) => ({
    catalogOpen: false,
    activeCatalogRootId: initialRootId,
    pendingRootId: null,
    hoverTimer: null,
    setActive(id, delay = 140) {
        if (this.activeCatalogRootId === id) {
            this.pendingRootId = null;
            return;
        }
        this.pendingRootId = id;
        clearTimeout(this.hoverTimer);
        this.hoverTimer = setTimeout(() => {
            if (this.pendingRootId === id) {
                this.activeCatalogRootId = id;
            }
        }, delay);
    },
    cancelPending(id) {
        if (this.pendingRootId !== id) {
            return;
        }
        clearTimeout(this.hoverTimer);
        this.pendingRootId = null;
    },
    setActiveInstant(id) {
        clearTimeout(this.hoverTimer);
        this.pendingRootId = null;
        this.activeCatalogRootId = id;
    },
    clearTimer() {
        clearTimeout(this.hoverTimer);
    },
});

const compareEqualizerFactory = () => ({
    observer: null,
    rafId: 0,
    isMeasuring: false,

    init() {
        this.reobserve();
        window.addEventListener('load', () => this.queueMeasure(), { once: true });
    },

    destroy() {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = 0;
        }

        try {
            this.observer?.disconnect();
        } catch {
            //
        }
    },

    queueMeasure() {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
        }

        this.rafId = requestAnimationFrame(() => {
            this.rafId = 0;
            this.measure();
        });
    },

    observeCurrentNodes() {
        const nodes = this.$root.querySelectorAll('.js-attr-head, .js-val-head, .js-attr-row, .js-val-row');
        nodes.forEach((element) => this.observer?.observe(element));
    },

    setMinHeight(element, value) {
        if (!element) {
            return;
        }

        if (element.style.minHeight !== value) {
            element.style.minHeight = value;
        }
    },

    reobserve() {
        try {
            this.observer?.disconnect();
        } catch {
            //
        }

        this.observer = new ResizeObserver(() => this.queueMeasure());

        this.observeCurrentNodes();

        this.$nextTick(() => this.queueMeasure());
    },

    measure() {
        if (this.isMeasuring) {
            return;
        }

        this.isMeasuring = true;

        const isMdUp = window.matchMedia('(min-width: 768px)').matches;

        const leftHead = this.$root.querySelector('.js-attr-head');
        const rightHeads = Array.from(this.$root.querySelectorAll('.js-val-head'));
        const leftRows = Array.from(this.$root.querySelectorAll('.js-attr-row'));
        const rightRows = Array.from(this.$root.querySelectorAll('.js-val-row'));
        const allRows = [leftHead, ...rightHeads, ...leftRows, ...rightRows];

        try {
            this.observer?.disconnect();

            if (!isMdUp) {
                allRows.forEach((element) => this.setMinHeight(element, ''));

                return;
            }

            const leftHeadHeight = leftHead ? leftHead.offsetHeight : 0;
            const rightHeadMax = rightHeads.reduce((max, element) => Math.max(max, element.offsetHeight || 0), 0);
            const headHeight = Math.max(leftHeadHeight, rightHeadMax);
            const headMinHeight = headHeight ? `${headHeight}px` : '';

            this.setMinHeight(leftHead, headMinHeight);
            rightHeads.forEach((element) => this.setMinHeight(element, headMinHeight));

            const rightRowsByIndex = rightRows.reduce((accumulator, element) => {
                const index = Number(element.dataset.i ?? -1);
                if (!accumulator[index]) {
                    accumulator[index] = [];
                }
                accumulator[index].push(element);

                return accumulator;
            }, {});

            leftRows.forEach((leftElement) => {
                const index = Number(leftElement.dataset.i ?? -1);
                const leftHeight = leftElement.offsetHeight || 0;
                const matchingRows = rightRowsByIndex[index] || [];
                const rightHeight = matchingRows.reduce((max, element) => Math.max(max, element.offsetHeight || 0), 0);
                const rowHeight = Math.max(leftHeight, rightHeight);
                const rowMinHeight = rowHeight ? `${rowHeight}px` : '';

                this.setMinHeight(leftElement, rowMinHeight);
                matchingRows.forEach((element) => {
                    this.setMinHeight(element, rowMinHeight);
                });
            });
        } finally {
            this.observeCurrentNodes();
            this.isMeasuring = false;
        }
    },
});

const registerAlpineData = () => {
    if (typeof window === 'undefined') return;

    const alpine = window.Alpine;
    if (!alpine?.data) return;
    if (window.__stankomanAlpineDataRegistered) return;

    window.__stankomanAlpineDataRegistered = true;

    alpine.plugin(tooltip);
    alpine.data('navDropdown', navDropdownFactory);
    alpine.data('catalogMenu', catalogMenuFactory);
    alpine.data('compareEqualizer', compareEqualizerFactory);
    alpine.data('prettyNumberInput', prettyNumberInputFactory);
};

document.addEventListener('alpine:init', registerAlpineData);
registerAlpineData();
