export default () => ({
    openState: false,
    product: null,
    closeTimer: null,

    open(product = null) {
        if (!product || typeof product !== 'object') {
            return;
        }

        if (this.closeTimer) {
            clearTimeout(this.closeTimer);
            this.closeTimer = null;
        }

        this.product = product;
        this.openState = true;
        document.documentElement.classList.add('overflow-hidden');
    },

    close() {
        this.openState = false;
        document.documentElement.classList.remove('overflow-hidden');

        if (this.closeTimer) {
            clearTimeout(this.closeTimer);
        }

        this.closeTimer = setTimeout(() => {
            if (!this.openState) {
                this.product = null;
            }

            this.closeTimer = null;
        }, 220);
    },
});
