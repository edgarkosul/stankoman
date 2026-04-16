import tooltip from './plugins/tooltip';
import cartModalFactory from './alpine/cart-modal';
import { initRuPhoneMask } from './modules/phone-mask-ru';
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

const equalizeProductSliderHeights = (slider) => {
    if (!(slider instanceof Element)) {
        return;
    }

    const cards = Array.from(slider.querySelectorAll(':scope > .swiper-wrapper > .swiper-slide > *'))
        .filter((node) => node instanceof HTMLElement);

    if (!cards.length) {
        return;
    }

    cards.forEach((card) => {
        card.style.height = '';
        card.style.minHeight = '';
    });

    let maxHeight = 0;
    cards.forEach((card) => {
        maxHeight = Math.max(maxHeight, card.getBoundingClientRect().height);
    });

    if (maxHeight <= 0) {
        return;
    }

    const normalizedHeight = `${Math.ceil(maxHeight)}px`;
    cards.forEach((card) => {
        card.style.minHeight = normalizedHeight;
    });
};

const scheduleProductSliderEqualize = (slider) => {
    requestAnimationFrame(() => {
        equalizeProductSliderHeights(slider);
        slider.swiper?.update();
    });
};

const initProductSliders = (root = document) => {
    const sliderSelector = '.product-slider, .action-product-slider';
    const sliderElements = root instanceof Element && root.matches(sliderSelector)
        ? [root, ...root.querySelectorAll(sliderSelector)]
        : Array.from(root.querySelectorAll(sliderSelector));

    sliderElements.forEach((slider) => {
        const isActionSlider = slider.classList.contains('action-product-slider');

        if (slider.swiper) {
            slider.swiper.update();
            scheduleProductSliderEqualize(slider);
            return;
        }

        const swiper = new Swiper(slider, {
            modules: [A11y, FreeMode, Mousewheel, Navigation],
            slidesPerView: 2,
            spaceBetween: isActionSlider ? 2 : 10,
            breakpoints: {
                // 480: { slidesPerView: 2 },
                640: { slidesPerView: 2 },
                768: { slidesPerView: 3 },
                1024: { slidesPerView: 4 },
                1280: { slidesPerView: 5 },
            },
            navigation: {
                prevEl: slider.querySelector('[data-nav="prev"]'),
                nextEl: slider.querySelector('[data-nav="next"]'),
            },
            freeMode: { enabled: true },
            mousewheel: { forceToAxis: true },
        });

        swiper.on('resize', () => scheduleProductSliderEqualize(slider));
        swiper.on('breakpoint', () => scheduleProductSliderEqualize(slider));
        swiper.on('slideChangeTransitionEnd', () => scheduleProductSliderEqualize(slider));

        scheduleProductSliderEqualize(slider);
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

const escapeTooltipHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const overflowTooltipFactory = (content = '') => ({
    content,
    observer: null,
    rafId: 0,
    resizeHandler: null,
    tooltipContent: '',

    init() {
        this.queueSync();

        if (typeof ResizeObserver !== 'undefined') {
            this.observer = new ResizeObserver(() => this.queueSync());
            this.observer.observe(this.$el);

            return;
        }

        this.resizeHandler = () => this.queueSync();
        window.addEventListener('resize', this.resizeHandler);
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

        if (this.resizeHandler) {
            window.removeEventListener('resize', this.resizeHandler);
            this.resizeHandler = null;
        }
    },

    queueSync() {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
        }

        this.rafId = requestAnimationFrame(() => {
            this.rafId = 0;
            this.sync();
        });
    },

    sync() {
        const element = this.$el;

        if (!(element instanceof HTMLElement)) {
            this.tooltipContent = '';

            return;
        }

        const isOverflowing = element.scrollWidth > element.clientWidth;

        this.tooltipContent = isOverflowing
            ? escapeTooltipHtml(this.content)
            : '';
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

const parseEcommerceMarker = (selector) => {
    const marker = document.querySelector(selector);

    if (!(marker instanceof HTMLScriptElement)) {
        return null;
    }

    if (marker.dataset.ecommerceConsumed === 'true') {
        return null;
    }

    try {
        const payload = JSON.parse(marker.textContent || 'null');

        marker.dataset.ecommerceConsumed = 'true';

        return payload;
    } catch (error) {
        console.warn('Failed to parse ecommerce marker', error);

        return null;
    }
};

const pushEcommercePayload = (payload) => {
    if (!payload || typeof payload !== 'object') {
        return;
    }

    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        ecommerce: payload,
    });
};

const trackEcommerceDetail = (payload) => {
    const product = payload?.detail?.products?.[0];

    if (!product?.id) {
        return;
    }

    pushEcommercePayload(payload);
};

const trackEcommercePurchase = (payload) => {
    const purchaseId = payload?.purchase?.actionField?.id;

    if (!purchaseId) {
        return;
    }

    const storageKey = `stankoman:ecommerce:purchase:${purchaseId}`;

    try {
        if (window.sessionStorage.getItem(storageKey) === '1') {
            return;
        }

        window.sessionStorage.setItem(storageKey, '1');
    } catch (error) {
        console.warn('Failed to persist ecommerce purchase dedupe key', error);
    }

    pushEcommercePayload(payload);
};

const consumePageEcommerceMarkers = () => {
    const detailPayload = parseEcommerceMarker('script[data-ecommerce-detail]');

    if (detailPayload) {
        trackEcommerceDetail(detailPayload);
    }

    const purchasePayload = parseEcommerceMarker('script[data-ecommerce-purchase]');

    if (purchasePayload) {
        trackEcommercePurchase(purchasePayload);
    }
};

if (typeof window !== 'undefined') {
    window.stankomanEcommerce = {
        trackAdd(payload) {
            pushEcommercePayload(payload);
        },
        trackDetail(payload) {
            trackEcommerceDetail(payload);
        },
        trackPurchase(payload) {
            trackEcommercePurchase(payload);
        },
    };
}

let yandexMetrikaNavigationTracked = false;

const trackYandexMetrikaPageView = () => {
    const counterId = Number(document.querySelector('meta[name="yandex-metrika-id"]')?.content);

    if (!Number.isInteger(counterId) || typeof window.ym !== 'function') {
        return;
    }

    if (!yandexMetrikaNavigationTracked) {
        yandexMetrikaNavigationTracked = true;
        window.yandexMetrikaLastUrl = window.location.href;

        return;
    }

    const currentUrl = window.location.href;
    const referer = typeof window.yandexMetrikaLastUrl === 'string' && window.yandexMetrikaLastUrl !== ''
        ? window.yandexMetrikaLastUrl
        : document.referrer;

    window.ym(counterId, 'hit', currentUrl, {
        referer,
        title: document.title,
    });

    window.yandexMetrikaLastUrl = currentUrl;
};

document.addEventListener('DOMContentLoaded', initImageGalleries);
document.addEventListener('livewire:navigated', initImageGalleries);
document.addEventListener('DOMContentLoaded', initHeroSliders);
document.addEventListener('livewire:navigated', initHeroSliders);
document.addEventListener('DOMContentLoaded', () => initProductSliders(document));
document.addEventListener('livewire:navigated', () => initProductSliders(document));
document.addEventListener('DOMContentLoaded', initImageGalleryLightbox);
document.addEventListener('livewire:navigated', initImageGalleryLightbox);
document.addEventListener('DOMContentLoaded', initProductCardSwipers);
document.addEventListener('livewire:navigated', initProductCardSwipers);
document.addEventListener('DOMContentLoaded', consumePageEcommerceMarkers);
document.addEventListener('livewire:navigated', consumePageEcommerceMarkers);
document.addEventListener('DOMContentLoaded', () => initNouisliderOnRange(document));
document.addEventListener('livewire:navigated', () => initNouisliderOnRange(document));
document.addEventListener('DOMContentLoaded', () => initCompareSwipers(document));
document.addEventListener('livewire:navigated', () => initCompareSwipers(document));
document.addEventListener('livewire:navigated', trackYandexMetrikaPageView);
document.addEventListener('ecommerce:add-to-cart', (event) => {
    window.stankomanEcommerce?.trackAdd(event.detail?.payload ?? null);
});
document.addEventListener('ecommerce:purchase', (event) => {
    window.stankomanEcommerce?.trackPurchase(event.detail?.payload ?? null);
});

let productCardSwiperHooked = false;
document.addEventListener('livewire:initialized', () => {
    if (productCardSwiperHooked) {
        return;
    }

    productCardSwiperHooked = true;

    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.added', () => {
            initProductSliders();
            initProductCardSwipers();
        });
    }
});

document.addEventListener('livewire:init', () => {
    if (!window.Livewire) return;

    Livewire.on('auth:redirect', (payload) => {
        const url = typeof payload === 'string' ? payload : payload?.url;
        if (typeof url !== 'string' || url === '') {
            return;
        }

        window.location.assign(url);
    });

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

        initProductSliders(scope);
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
    touchFocusRootId: null,
    touchFocusStartedAt: 0,
    armedRootId: null,
    preventedClickRootId: null,
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
    isTouchEnvironment() {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return false;
        }

        return window.matchMedia('(hover: none), (pointer: coarse)').matches || (navigator.maxTouchPoints || 0) > 0;
    },
    isBelowXsNavigationBreakpoint() {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return false;
        }

        return window.matchMedia('(max-width: 479px)').matches;
    },
    isTouchEvent(event) {
        if (!event) {
            return false;
        }

        if (event.type.startsWith('touch')) {
            return true;
        }

        if (typeof event.pointerType === 'string') {
            return event.pointerType === 'touch' || this.isTouchEnvironment();
        }

        return this.isTouchEnvironment();
    },
    prepareTouchActivation(event, id) {
        if (!this.isTouchEvent(event)) {
            return;
        }

        if (this.isBelowXsNavigationBreakpoint()) {
            this.resetTouchInteraction();

            return;
        }

        const now = Date.now();
        if (this.touchFocusRootId === id && now - this.touchFocusStartedAt < 64) {
            return;
        }

        this.touchFocusRootId = id;
        this.touchFocusStartedAt = now;
        this.preventedClickRootId = this.armedRootId === id ? null : id;
        this.armedRootId = id;
        this.setActiveInstant(id);
    },
    handleFocus(id) {
        if (this.isBelowXsNavigationBreakpoint()) {
            return;
        }

        if (this.touchFocusRootId === id && Date.now() - this.touchFocusStartedAt < 500) {
            return;
        }

        this.setActiveInstant(id);
    },
    handleRootClick(event, id) {
        if (this.isBelowXsNavigationBreakpoint()) {
            this.resetTouchInteraction();

            return;
        }

        if (this.preventedClickRootId === id) {
            event.preventDefault();
            event.stopPropagation();
        }

        this.resetTouchInteraction(id);
    },
    resetTouchInteraction(id = null) {
        if (id === null || this.touchFocusRootId === id) {
            this.touchFocusRootId = null;
            this.touchFocusStartedAt = 0;
        }

        if (id === null || this.preventedClickRootId === id) {
            this.preventedClickRootId = null;
        }

        if (id === null) {
            this.armedRootId = null;
        }
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

const registerRecentProductsStore = (alpine) => {
    if (!alpine?.store) {
        return;
    }

    if (alpine.store('recent')) {
        return;
    }

    const storageKey = 'stankoman:recent:v1';
    const emptyState = { v: 1, ids: [], updatedAt: 0 };

    const readState = () => {
        try {
            const raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return { ...emptyState };
            }

            const parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.ids)) {
                return { ...emptyState };
            }

            const ids = parsed.ids
                .map((value) => Number(value))
                .filter((value) => Number.isInteger(value) && value > 0)
                .slice(0, 20);

            return {
                v: 1,
                ids,
                updatedAt: Number(parsed.updatedAt ?? 0) || 0,
            };
        } catch {
            return { ...emptyState };
        }
    };

    const persistState = (state) => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch {
            //
        }
    };

    alpine.store('recent', {
        state: readState(),
        add(id) {
            const numericId = Number(id);
            if (!Number.isInteger(numericId) || numericId <= 0) {
                return;
            }

            const currentIds = Array.isArray(this.state?.ids) ? this.state.ids : [];
            const ids = [numericId, ...currentIds.filter((value) => value !== numericId)].slice(0, 20);

            this.state = {
                v: 1,
                ids,
                updatedAt: Math.floor(Date.now() / 1000),
            };

            persistState(this.state);
        },
        ids() {
            return Array.isArray(this.state?.ids) ? this.state.ids : [];
        },
        clear() {
            this.state = { ...emptyState };
            persistState(this.state);
        },
    });
};

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
    alpine.data('overflowTooltip', overflowTooltipFactory);
    alpine.data('prettyNumberInput', prettyNumberInputFactory);
    alpine.data('cartModal', cartModalFactory);
    registerRecentProductsStore(alpine);
};

document.addEventListener('alpine:init', registerAlpineData);
registerAlpineData();
initRuPhoneMask();
