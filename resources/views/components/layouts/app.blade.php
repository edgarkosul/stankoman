@props(['title' => null, 'seo' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green scrollbar-track-zinc-50">

<head>
    <meta name="theme-color" content="#ffffff">
    @include('partials.head', ['head' => $head ?? null, 'title' => $title])
    @stack('head')
    @stack('styles')
</head>

<body>
    @include('partials.yandex-metrika-noscript')

    <div class="flex min-h-screen flex-col">
        <x-layouts.partials.info />
        <x-layouts.partials.header />
        <x-navigation.breadcrumbs />
        <main class="flex flex-1 flex-col">
            {{ $slot }}
        </main>
        <x-layouts.partials.footer />
    </div>

    @include('partials.cart-modal')
    <livewire:auth.login-inline />
    <livewire:auth.register-inline />
    <livewire:auth.forgot-password-inline />
    <livewire:auth.verify-email-inline />

    <script>
        window.prettyNumberInput = window.prettyNumberInput || function (config = {}) {
            return {
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
            };
        };

        window.navDropdown = window.navDropdown || function () {
            return {
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
            };
        };

        window.catalogMenu = window.catalogMenu || function (initialRootId = null) {
            return {
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
                    if (! event) {
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
                    if (! this.isTouchEvent(event)) {
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
            };
        };

        document.addEventListener('alpine:init', () => {
            if (window.Alpine?.data) {
                window.Alpine.data('navDropdown', window.navDropdown);
                window.Alpine.data('catalogMenu', window.catalogMenu);
                window.Alpine.data('prettyNumberInput', window.prettyNumberInput);
            }
        });
    </script>

    @fluxScripts
    @stack('scripts')
</body>

</html>
