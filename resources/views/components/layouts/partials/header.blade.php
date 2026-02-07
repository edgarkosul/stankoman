<header class="">
    <div class="bg-zinc-100 ">
        <div class="flex max-w-7xl m-auto py-2 px-2 xs:px-3 sm:px-4 md:px-6 gap-2  justify-between">
            <div class="flex  flex-col gap-8">
                <div class="flex items-center gap-2 text-sm">
                    <x-tooltip {{-- title="Режим работы:" --}} subtitle="г. Краснодар, трасса М4-ДОН">
                        <x-slot:trigger>
                            <span class="inline-flex items-center gap-2">
                                <x-icon name="spot" class="w-5 h-5" />
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
                            <x-icon name="max" class="w-5 h-5" />
                        </a>
                        <a href="tg://resolve?phone=79002468660">
                            <x-icon name="telegram" class="w-5 h-5" />
                        </a>
                        <a href="tel:+79002468660" class="flex gap-2">
                            <x-icon name="phone" class="w-5 h-5" />
                            <span class="whitespace-nowrap hidden xs:block">+7 (900) 246-86-60</span>
                        </a>
                    </div>
                </div>
                <div class="border-r border border-zinc-300"></div>
                <div x-data x-tooltip.smart.bottom.offset-10.lt-md="'sale@kratonkuban.ru'" class="flex flex-col gap-8">
                    <a href="mailto:sale@kratonkuban.ru">
                        <div class="flex items-center md:gap-2 text-sm">
                            <x-icon name="email" class="w-5 h-5 " />
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
                                    <x-icon name="info" class="w-5 h-5" />
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
    <div class="max-w-7xl mx-auto px-2 xs:px-3 sm:px-4 md:px-6 py-4 flex items-center flex-wrap lg:flex-nowrap justify-between gap-4 xs:gap-6 md:gap-8">
        {{-- LOGO --}}
        <div class="min-w-0 max-w-52 order-0 ">
            <a href="{{ route('home') }}" aria-label="На главную">
                <x-icon name="logo" class="w-full h-auto hidden xs:block" />
                <x-icon name="logo_sq" class="size-14 ml-2 xs:hidden" />
            </a>
        </div>
        {{-- КАТАЛОГ + ПОИСК --}}
        <div class="flex-1 flex gap-4 min-w-3xs sm:min-w-142 order-2 lg:order-1">
            <button x-data x-tooltip.smart.bottom.offset-10.lt-xl="'КАТАЛОГ'"
                class="flex items-center gap-2 bg-brand-green green hover:bg-[#1c7731] text-white wdth-80 font-bold h-11 px-4 cursor-pointer"><x-icon
                    name="katalog" class="w-5 h-5 text-white" /><span class="hidden xl:block">КАТАЛОГ</span></button>
            <livewire:header.search class="flex-1 min-w-0" />
        </div>
        {{-- ИКОНКИ ПРАВЫЕ --}}
        <div class="grid grid-cols-4 gap-6 xl:gap-4 order-1 lg:oreder-2">
            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Войти'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="user" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Войти</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Сравнение'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="compare" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Сравнение</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Избранное'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="bokmark" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Избранное</span>
                </div>
            </div>

            <div x-data x-tooltip.smart.bottom.offset-10.lt-xl="'Корзина'"
                class="flex-1 flex flex-col items-center text-sm cursor-pointer">
                <x-icon name="cart" class="size-6 xl:size-5 -translate-y-0.5" />
                <div>
                    <span class="hidden xl:block">Корзина</span>
                </div>
            </div>
        </div>

    </div>
</header>
