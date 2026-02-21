<header class="sticky top-0 z-50 shadow-md"
    x-data="catalogMenu(@js($catalogMenuActiveRootId))"
    @keydown.escape.window="catalogOpen = false"
>

    <div class="bg-zinc-100 ">
        <div class="flex max-w-7xl m-auto py-2 px-2 xs:px-3 sm:px-4 md:px-6 gap-2  justify-between">
            <div class="flex  flex-col gap-8">
                <div class="flex items-center gap-2 text-sm">
                    <x-tooltip {{-- title="Режим работы:" --}} subtitle="г. Краснодар, трасса М4-ДОН">
                        <x-slot:trigger>
                            <span class="inline-flex items-center gap-2">
                                <x-icon name="spot" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                                <span class="hidden md:block">Краснодар</span>
                            </span>
                        </x-slot:trigger>
                        пос. Новознаменский, ул. Андреевская, 2
                    </x-tooltip>
                </div>
            </div>

            <div class="flex gap-3">
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-3 text-sm">
                        <a href="https://max.ru/" target="_blank">
                            <x-icon name="max" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                        </a>
                        <a href="tg://resolve?phone=79002468660">
                            <x-icon name="telegram" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                        </a>
                        <a href="tel:+79002468660" class="flex gap-2">
                            <x-icon name="phone" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                            <span class="whitespace-nowrap hidden xs:block">+7 (900) 246-86-60</span>
                        </a>
                    </div>
                </div>
                <div class="border-r border border-zinc-300"></div>
                <div x-data x-tooltip.smart.bottom.offset-10.lt-md="'sale@kratonkuban.ru'" class="flex flex-col gap-8">
                    <a href="mailto:sale@kratonkuban.ru">
                        <div class="flex items-center md:gap-2 text-sm">
                            <x-icon name="email" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                            <div>
                                <span class="hidden md:block">sale@kratonkuban.ru</span>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="border-r border border-zinc-300"></div>
                <div class="flex flex-col gap-8">
                    <div class="flex items-center gap-2 text-sm">
                        <x-tooltip {{-- title="Режим работы:" --}} align="right" subtitle="ПН - Пт: 9:00 - 18:00"
                            subtitle2="Сб-Вс: выходной ">
                            <x-slot:trigger>
                                <span class="inline-flex items-center gap-2">
                                    <x-icon name="info" class="w-5 h-5 [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red" />
                                    <span class="whitespace-nowrap hidden xs:block">Режим работы</span>
                                </span>
                            </x-slot:trigger>
                            Любой текст из снипетов в админке
                        </x-tooltip>
                    </div>
                </div>
            </div>
            <x-header-menu />
        </div>
    </div>
    <div
        class=" max-w-7xl mx-auto px-2 xs:px-3 sm:px-4 md:px-6 py-4 flex items-center flex-wrap lg:flex-nowrap justify-between gap-4 xs:gap-6 md:gap-8 bg-white">
        {{-- LOGO --}}
        <div class="min-w-0 max-w-52 order-0 ">
            <a href="{{ route('home') }}" aria-label="На главную">
                <x-icon name="logo" class="w-full h-auto hidden xs:block [&_.brand-gray]:text-brand-gray [&_.white]:text-white [&_.brand-red]:text-brand-red [&_.brand-dark]:text-black" />
                <x-icon name="logo_sq" class="size-14 ml-2 xs:hidden [&_.icon-base]:text-zinc-700 [&_.icon-accent]:text-brand-red [&_.icon-muted]:text-zinc-400 [&_.icon-contrast]:text-white" />
            </a>
        </div>
        {{-- КАТАЛОГ + ПОИСК --}}
        <div class="flex-1 flex gap-4 min-w-3xs sm:min-w-142 order-2 lg:order-1">
            <button x-tooltip.smart.bottom.offset-10.lt-xl="'КАТАЛОГ'" @click.stop="catalogOpen = !catalogOpen"
                :aria-expanded="catalogOpen" aria-controls="catalog-nav"
                class="flex items-center gap-2 bg-brand-green green hover:bg-[#1c7731] text-white wdth-80 font-bold h-11 px-4 cursor-pointer">
                <x-icon x-show="!catalogOpen" name="katalog" class="w-5 h-5 text-white" />
                <x-icon x-show="catalogOpen" x-cloak name="x" class="w-5 h-5 text-white p-0.5" />
                <span class="hidden xl:block">КАТАЛОГ</span>
            </button>
            <livewire:header.search class="flex-1 min-w-0" />
        </div>
        {{-- ИКОНКИ ПРАВЫЕ --}}
        <div class="grid grid-cols-4 gap-6 xl:gap-4 order-1 lg:oreder-2">
            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Войти'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="user" class="size-6 xl:size-5 -translate-y-0.5 [&_.icon-base]:text-zinc-800 [&_.icon-accent]:text-brand-red" />
                <div>
                    <span class="hidden xl:block">Войти</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Сравнение'"
                class="flex-1 flex flex-col items-center text-sm">
                <livewire:header.compare-badge />
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Избранное'"
                class="flex-1 flex flex-col items-center text-sm">
                <livewire:header.favorites-badge />
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Корзина'"
                class="flex-1 flex flex-col items-center text-sm">
                <livewire:pages.cart.icon />
            </div>
        </div>
    </div>
    {{-- CATEGORIES MENU --}}
    <nav id="catalog-nav" x-show="catalogOpen" x-cloak @click.outside="catalogOpen = false"
        class="absolute inset-x-0 z-50">
        <div class="max-w-7xl mx-auto bg-zinc-50">
            <!-- Общая высота меню -->
            <div class="h-[70vh] max-h-screen overflow-hidden">
                <!-- 1) слева фикс 2) справа остаток -->
                <div class="grid h-full min-h-0 grid-cols-1 xs:grid-cols-[280px_minmax(0,1fr)] gap-1">

                    <!-- ЛЕВАЯ КОЛОНКА (фикс ширина + свой скролл) -->
                    <aside
                        class="min-h-0 overflow-y-auto bg-zinc-500/30 py-6 overscroll-contain scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green/70 scrollbar-track-zinc-50 text-lg font-semibold">
                        @forelse ($catalogMenuRoots as $root)
                            <a
                                href="{{ route('catalog.leaf', ['path' => $root['menu_path']]) }}"
                                class="block px-6 py-3 hover:text-brand-gray hover:bg-zinc-50"
                                @mouseenter="setActive({{ $root['id'] }})"
                                @mouseleave="cancelPending({{ $root['id'] }})"
                                @focus="setActiveInstant({{ $root['id'] }})"
                                :class="activeCatalogRootId === {{ $root['id'] }} ? 'text-brand-gray bg-zinc-50' : ''"
                            >
                                {{ $root['name'] }}
                            </a>
                        @empty
                            <p class="text-sm text-zinc-600">Категорий пока нет.</p>
                        @endforelse
                    </aside>

                    <!-- ПРАВАЯ ОБЛАСТЬ (остаток + свой скролл) -->
                    <section
                        class="min-h-0 min-w-0 overflow-y-auto px-3 py-6 overscroll-contain scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green/70 scrollbar-track-zinc-50">
                        @forelse ($catalogMenuRoots as $root)
                            <div x-show="activeCatalogRootId === {{ $root['id'] }}" x-cloak>
                                <!-- Автоколонки: 1 -> 2 -> 3 -> 4 -->
                                <div class="columns-1 md:columns-2 xl:columns-3 2xl:columns-4 gap-8 [column-fill:balance]">
                                    <!-- ВАЖНО: каждый блок не должен рваться между колонками -->
                                    @forelse ($root['children'] as $group)
                                        <article class="mb-8 break-inside-avoid">
                                            <h3 class="text-lg font-semibold ">
                                                <a
                                                    href="{{ route('catalog.leaf', ['path' => $group['menu_path']]) }}"
                                                    class="hover:underline hover:decoration-brand-red"
                                                >
                                                    {{ $group['name'] }}
                                                </a>
                                            </h3>
                                            @if (!empty($group['children']))
                                                <ul class="mt-2 space-y-1">
                                                    @foreach ($group['children'] as $leaf)
                                                        <li>
                                                            <a
                                                                href="{{ route('catalog.leaf', ['path' => $leaf['menu_path']]) }}"
                                                                class="hover:underline hover:decoration-brand-red ml-1"
                                                            >
                                                                {{ $leaf['name'] }}
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </article>
                                    @empty
                                        <p class="text-sm text-zinc-600">Подкатегорий пока нет.</p>
                                    @endforelse
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-600">Каталог пуст.</p>
                        @endforelse
                    </section>
                </div>
            </div>
        </div>
    </nav>


</header>
