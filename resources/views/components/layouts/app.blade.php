@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green scrollbar-track-zinc-50">

<head>
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
<link rel="icon" href="/favicon-32x32.png?v=2" type="image/png" sizes="32x32">
<link rel="icon" href="/favicon-16x16.png?v=2" type="image/png" sizes="16x16">

<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2">

<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#ffffff">
    @include('partials.head', ['title' => $title])
    @stack('head')
    @stack('styles')
</head>

<body>
    <div class="flex min-h-screen flex-col">
        <x-layouts.partials.info />
        <x-layouts.partials.header />
        <x-navigation.breadcrumbs />
        <main class="flex-1">
            {{ $slot }}
        </main>
        <x-layouts.partials.footer />
    </div>

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
