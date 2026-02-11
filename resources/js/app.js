import tooltip from './plugins/tooltip';
import Swiper from 'swiper';
import { Autoplay, FreeMode, Navigation, Pagination, Thumbs } from 'swiper/modules';
import PhotoSwipeLightbox from 'photoswipe/lightbox';
import 'photoswipe/style.css';

Alpine.plugin(tooltip)

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

        const thumbsSwiper = new Swiper(thumbsEl, {
            modules: [FreeMode, Thumbs],
            spaceBetween: 10,
            slidesPerView: 4,
            freeMode: true,
            watchSlidesProgress: true,
        });

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

        swiperEl.addEventListener('mouseleave', () => {
            if (!desktopQuery.matches || swiper.destroyed) {
                return;
            }

            swiper.slideTo(0, 120, false);
            lastIndex = -1;
        });

        swiperEl.dataset.productCardSwiperInitialized = 'true';
    });
};

let imageGalleryLightbox = null;

const initImageGalleryLightbox = () => {
    if (imageGalleryLightbox) {
        imageGalleryLightbox.destroy();
        imageGalleryLightbox = null;
    }

    if (!document.querySelector('[data-image-gallery-main] a')) {
        return;
    }

    imageGalleryLightbox = new PhotoSwipeLightbox({
        gallery: '[data-image-gallery-main]',
        children: 'a',
        pswpModule: () => import('photoswipe'),
    });

    imageGalleryLightbox.init();
};

document.addEventListener('DOMContentLoaded', initImageGalleries);
document.addEventListener('livewire:navigated', initImageGalleries);
document.addEventListener('DOMContentLoaded', initHeroSliders);
document.addEventListener('livewire:navigated', initHeroSliders);
document.addEventListener('DOMContentLoaded', initImageGalleryLightbox);
document.addEventListener('livewire:navigated', initImageGalleryLightbox);
document.addEventListener('DOMContentLoaded', initProductCardSwipers);
document.addEventListener('livewire:navigated', initProductCardSwipers);

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


document.addEventListener('alpine:init', () => {
    Alpine.data('navDropdown', () => ({
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
    }));

    Alpine.data('catalogMenu', (initialRootId = null) => ({
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
    }));
});
