import tooltip from './plugins/tooltip';
import Swiper from 'swiper';
import { Autoplay, FreeMode, Navigation, Thumbs } from 'swiper/modules';
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
});
