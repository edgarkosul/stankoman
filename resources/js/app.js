import tooltip from './plugins/tooltip';

Alpine.plugin(tooltip)


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
