<header class="sticky top-0 z-50" x-data="{ catalogOpen: false }" @keydown.escape.window="catalogOpen = false">

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
    <div
        class=" max-w-7xl mx-auto px-2 xs:px-3 sm:px-4 md:px-6 py-4 flex items-center flex-wrap lg:flex-nowrap justify-between gap-4 xs:gap-6 md:gap-8 bg-white">
        {{-- LOGO --}}
        <div class="min-w-0 max-w-52 order-0 ">
            <a href="{{ route('home') }}" aria-label="На главную">
                <x-icon name="logo" class="w-full h-auto hidden xs:block" />
                <x-icon name="logo_sq" class="size-14 ml-2 xs:hidden" />
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
                        class="min-h-0 overflow-y-auto bg-zinc-500/30 px-5 py-6 overscroll-contain scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green/70 scrollbar-track-zinc-50 space-y-3 text-lg font-semibold">
                        <p>Инструмент</p>
                        <p>Генераторы</p>
                        <p>Станки</p>
                        <p>Ручной инструмент</p>
                        <p>Сад и дача</p>
                        <p>Сварочное оборудование</p>
                        <p>Климат</p>
                        <p>Клининг</p>
                        <p>Насосы и водоснабжение</p>
                        <p>Компрессоры и пневмоинструмент</p>
                        <p>Товары для дома и офиса</p>
                        <p>Лестницы, леса, вышки-туры</p>
                        <p>Строительство</p>
                        <p>Производство</p>
                        <p>Склад</p>
                        <p>Автотовары</p>
                        <p>Дорожная продукция</p>
                        <p>Расходка и крепеж</p>
                        <p>Электрика и свет</p>
                        <p>Отдых и туризм</p>
                        <p>Электроинструмент</p>
                        <p>Измерительный инструмент</p>
                        <p>Оснастка и расходники</p>
                        <p>Ручные столярные инструменты</p>
                        <p>Садовая техника</p>
                        <p>Бытовая техника</p>
                        <p>Вентиляция и кондиционирование</p>
                        <p>Отопительное оборудование</p>
                        <p>Системы полива</p>
                        <p>Лакокрасочные материалы</p>
                        <p>Сухие строительные смеси</p>
                        <p>Крепеж и метизы</p>
                        <p>Пиломатериалы</p>
                        <p>Изоляция и утеплители</p>
                        <p>Сантехника и водоотведение</p>
                        <p>Безопасность и охрана труда</p>
                        <p>Крепежные элементы</p>
                        <p>Фурнитура и комплектующие</p>
                        <p>Освещение и электромонтаж</p>
                        <p>Аккумуляторная техника</p>
                        <p>...</p>
                    </aside>

                    <!-- ПРАВАЯ ОБЛАСТЬ (остаток + свой скролл) -->
                    <section
                        class="min-h-0 min-w-0 overflow-y-auto p-3 overscroll-contain scrollbar-w-0.5 scrollbar scrollbar-thumb-brand-green/70 scrollbar-track-zinc-50">
                        <!-- Автоколонки: 1 -> 2 -> 3 -> 4 -->
                        <div class="columns-1 md:columns-2 xl:columns-3 2xl:columns-4 gap-8 [column-fill:balance]">
                            <!-- ВАЖНО: каждый блок не должен рваться между колонками -->
                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Станки</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Деревообрабатывающие станки</li>
                                    <li>Металлообрабатывающие станки</li>
                                    <li>Принадлежности для станков</li>
                                    <li>Станки для обработки камня</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Пескоструйное оборудование</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Пескоструйные аппараты</li>
                                    <li>Пескоструйные камеры</li>
                                    <li>Принадлежности для аппаратов</li>
                                    <li>Принадлежности для камер</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Пневмоинструмент</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Пневмопистолеты</li>
                                    <li>Наборы пневмоинструмента</li>
                                    <li>Пневмогайковерты</li>
                                    <li>Пневмошлифмашины</li>
                                    <li>Пневмодрели</li>
                                    <li>Пневмостеплеры</li>
                                    <li>Пневмошуруповерты</li>
                                    <li>...</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Средства защиты</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Защита от тока</li>
                                    <li>Спецодежда</li>
                                    <li>СИЗ органов дыхания</li>
                                    <li>СИЗ органов зрения и головы</li>
                                    <li>СИЗ органов слуха</li>
                                    <li>СИЗ рук и ног</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Электроинструмент</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Дрели и шуруповерты</li>
                                    <li>Перфораторы</li>
                                    <li>Углошлифмашины</li>
                                    <li>Лобзики и пилы</li>
                                    <li>Фрезеры</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Оснастка и расходники</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Сверла и коронки</li>
                                    <li>Диски отрезные</li>
                                    <li>Шлифовальные круги</li>
                                    <li>Наборы бит</li>
                                    <li>Пильные полотна</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Генераторы</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Бензиновые генераторы</li>
                                    <li>Дизельные генераторы</li>
                                    <li>Инверторные генераторы</li>
                                    <li>Стабилизаторы</li>
                                    <li>Аксессуары</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Компрессоры</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Поршневые компрессоры</li>
                                    <li>Винтовые компрессоры</li>
                                    <li>Осушители воздуха</li>
                                    <li>Ресиверы</li>
                                    <li>Масла и фильтры</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Насосы и водоснабжение</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Скважинные насосы</li>
                                    <li>Поверхностные насосы</li>
                                    <li>Дренажные насосы</li>
                                    <li>Насосные станции</li>
                                    <li>Автоматика</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Садовая техника</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Газонокосилки</li>
                                    <li>Триммеры</li>
                                    <li>Культиваторы</li>
                                    <li>Снегоуборщики</li>
                                    <li>Садовые пылесосы</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Климатическая техника</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Обогреватели</li>
                                    <li>Тепловые пушки</li>
                                    <li>Вентиляторы</li>
                                    <li>Осушители</li>
                                    <li>Увлажнители</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Сварочное оборудование</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Сварочные аппараты</li>
                                    <li>Полуавтоматы</li>
                                    <li>Аргонодуговая сварка</li>
                                    <li>Электроды и проволока</li>
                                    <li>Маски и аксессуары</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Лакокрасочные материалы</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Краски и эмали</li>
                                    <li>Лаки и грунты</li>
                                    <li>Шпатлевки</li>
                                    <li>Растворители</li>
                                    <li>Инструменты для покраски</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Строительные смеси</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Цемент</li>
                                    <li>Штукатурки</li>
                                    <li>Плиточный клей</li>
                                    <li>Наливные полы</li>
                                    <li>Шпаклевки</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Освещение и электромонтаж</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Светильники</li>
                                    <li>Лампы и прожекторы</li>
                                    <li>Кабель и провод</li>
                                    <li>Автоматы и щиты</li>
                                    <li>Розетки и выключатели</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Крепеж и метизы</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Болты и гайки</li>
                                    <li>Саморезы</li>
                                    <li>Анкеры</li>
                                    <li>Дюбели</li>
                                    <li>Шайбы и шпильки</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Лестницы и подмости</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Стремянки</li>
                                    <li>Телескопические лестницы</li>
                                    <li>Вышки-туры</li>
                                    <li>Подмости</li>
                                    <li>Аксессуары</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Складское оборудование</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Стеллажи</li>
                                    <li>Роклы и тележки</li>
                                    <li>Погрузчики</li>
                                    <li>Контейнеры</li>
                                    <li>Упаковка</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Автотовары</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Домкраты</li>
                                    <li>Пусковые устройства</li>
                                    <li>Автохимия</li>
                                    <li>Компрессоры для шин</li>
                                    <li>Аксессуары</li>
                                </ul>
                            </article>

                            <article class="mb-8 break-inside-avoid">
                                <h3 class="font-semibold">Туризм и отдых</h3>
                                <ul class="mt-2 space-y-1">
                                    <li>Палатки</li>
                                    <li>Спальные мешки</li>
                                    <li>Кемпинговая мебель</li>
                                    <li>Газовые горелки</li>
                                    <li>Фонари</li>
                                </ul>
                            </article>

                            <!-- добавляй дальше блоки-группы -->
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </nav>


</header>
