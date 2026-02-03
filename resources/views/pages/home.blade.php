<x-layouts.app title="Главная">
    {{-- <section class="relative overflow-hidden bg-zinc-950 text-white">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-40 right-0 h-[28rem] w-[28rem] rounded-full bg-emerald-500/25 blur-3xl"></div>
            <div class="absolute -bottom-48 -left-10 h-[32rem] w-[32rem] rounded-full bg-amber-400/20 blur-3xl"></div>
            <div
                class="absolute inset-0 opacity-20 [background-size:36px_36px] [background-image:linear-gradient(to_right,rgba(255,255,255,0.08)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.08)_1px,transparent_1px)]">
            </div>
        </div>

        <div class="relative mx-auto flex max-w-7xl flex-col gap-12 px-4 py-16 sm:py-24 lg:flex-row lg:items-center">
            <div class="flex flex-1 flex-col gap-6">
                <div class="flex flex-col gap-3">
                    <p class="text-xs font-mono uppercase tracking-[0.4em] text-white/60">Промышленная оснастка</p>
                    <h1 class="text-balance text-4xl font-semibold wdth-45  leading-tight sm:text-5xl lg:text-6xl">
                        Stankoman — точный подбор станков под задачу
                    </h1>
                </div>
                <p class="max-w-xl text-pretty text-lg text-white/75">
                    Собираем решения под производство: от быстрых подборок до детальных спецификаций с
                    логистикой и пуско-наладкой. Дальше блоки наполним реальным контентом.
                </p>
                <div class="flex flex-wrap gap-3">
                    <flux:button variant="primary" type="button">Запросить подбор</flux:button>
                    <flux:button variant="ghost" type="button" class="text-white">Смотреть каталог</flux:button>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs uppercase tracking-[0.3em] text-white/50">Срок</p>
                        <p class="mt-2 text-lg font-semibold">от 24 часов</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs uppercase tracking-[0.3em] text-white/50">Формат</p>
                        <p class="mt-2 text-lg font-semibold">под ключ</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                        <p class="text-xs uppercase tracking-[0.3em] text-white/50">Опыт</p>
                        <p class="mt-2 text-lg font-semibold">с 2012 года</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-1 flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-6">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-white/60">Подбор</p>
                        <p class="mt-3 text-2xl font-semibold">Технические консультации</p>
                        <p class="mt-2 text-sm text-white/70">
                            Разберем задачу, нагрузки и точность. Сформируем карту требований.
                        </p>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-6">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-white/60">Поставка</p>
                        <p class="mt-3 text-2xl font-semibold">Контроль отгрузки</p>
                        <p class="mt-2 text-sm text-white/70">
                            Документы, логистика, сопровождение — все в одном потоке.
                        </p>
                    </article>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-6">
                    <p class="text-xs font-mono uppercase tracking-[0.3em] text-white/60">Цифровой бриф</p>
                    <p class="mt-3 text-2xl font-semibold">Собираем спецификацию онлайн</p>
                    <p class="mt-2 text-sm text-white/70">
                        Форматируем входящие параметры и фиксируем требования для точного просчета.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-2 text-xs uppercase tracking-[0.2em] text-white/50">
                        <span class="rounded-full border border-white/10 px-3 py-1">ISO 9001</span>
                        <span class="rounded-full border border-white/10 px-3 py-1">CE</span>
                        <span class="rounded-full border border-white/10 px-3 py-1">EAC</span>
                    </div>
                </div>
            </div>
        </div>
    </section> --}}

    <section class="bg-zinc-50">
        <div class="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-16">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex flex-col gap-2">
                    <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Каталог</p>
                    <h2 class="text-3xl font-semibold text-zinc-900">Направления</h2>
                </div>
                <p class="text-sm text-zinc-500">Секция станет динамической, когда подключим категории.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <article
                    class="group flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between text-xs font-mono uppercase tracking-[0.2em] text-zinc-400">
                        <span>01</span>
                        <span>Цех</span>
                    </div>
                    <div class="h-24 rounded-xl bg-gradient-to-br from-zinc-100 via-zinc-200 to-zinc-100"></div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-zinc-900">Металлообработка</h3>
                        <p class="text-sm text-zinc-500">Токарные, фрезерные, ленточнопильные.</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-2 text-sm font-medium text-zinc-700">
                        <span>Подробнее</span>
                        <span aria-hidden="true">→</span>
                    </div>
                </article>

                <article
                    class="group flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between text-xs font-mono uppercase tracking-[0.2em] text-zinc-400">
                        <span>02</span>
                        <span>Дерево</span>
                    </div>
                    <div class="h-24 rounded-xl bg-gradient-to-br from-amber-50 via-amber-100 to-amber-50"></div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-zinc-900">Деревообработка</h3>
                        <p class="text-sm text-zinc-500">Фуговальные, рейсмусовые, кромочные.</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-2 text-sm font-medium text-zinc-700">
                        <span>Подробнее</span>
                        <span aria-hidden="true">→</span>
                    </div>
                </article>

                <article
                    class="group flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between text-xs font-mono uppercase tracking-[0.2em] text-zinc-400">
                        <span>03</span>
                        <span>Сервис</span>
                    </div>
                    <div class="h-24 rounded-xl bg-gradient-to-br from-emerald-50 via-emerald-100 to-emerald-50"></div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-zinc-900">Оснастка</h3>
                        <p class="text-sm text-zinc-500">Комплектация, режущий инструмент, расходники.</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-2 text-sm font-medium text-zinc-700">
                        <span>Подробнее</span>
                        <span aria-hidden="true">→</span>
                    </div>
                </article>

                <article
                    class="group flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between text-xs font-mono uppercase tracking-[0.2em] text-zinc-400">
                        <span>04</span>
                        <span>Проекты</span>
                    </div>
                    <div class="h-24 rounded-xl bg-gradient-to-br from-sky-50 via-sky-100 to-sky-50"></div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-lg font-semibold text-zinc-900">Инжиниринг</h3>
                        <p class="text-sm text-zinc-500">Компоновка линий и поставка под ключ.</p>
                    </div>
                    <div class="mt-auto inline-flex items-center gap-2 text-sm font-medium text-zinc-700">
                        <span>Подробнее</span>
                        <span aria-hidden="true">→</span>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="relative overflow-hidden bg-white">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute right-0 top-0 h-56 w-56 rounded-full bg-emerald-100 blur-3xl"></div>
            <div class="absolute bottom-0 left-0 h-72 w-72 rounded-full bg-amber-100 blur-3xl"></div>
        </div>
        <div class="relative mx-auto max-w-7xl px-4 py-16">
            <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr]">
                <div class="flex flex-col gap-4">
                    <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Процесс</p>
                    <h2 class="text-3xl font-semibold text-zinc-900">От запроса до запуска оборудования</h2>
                    <p class="text-pretty text-sm text-zinc-600">
                        Здесь будет короткая история о том, как мы ведем проект и сопровождаем производство.
                        Сейчас оставили каркас для будущего контента.
                    </p>
                    <div class="grid gap-3">
                        <div class="rounded-xl border border-zinc-200 bg-white/80 p-4 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-900 text-xs font-semibold text-white">01</span>
                                <h3 class="text-base font-semibold text-zinc-900">Бриф и расчет</h3>
                            </div>
                            <p class="mt-2 text-sm text-zinc-600">Собираем входные данные и фиксируем KPI.</p>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white/80 p-4 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-900 text-xs font-semibold text-white">02</span>
                                <h3 class="text-base font-semibold text-zinc-900">Подбор и согласование</h3>
                            </div>
                            <p class="mt-2 text-sm text-zinc-600">Предлагаем варианты и согласуем комплектацию.</p>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white/80 p-4 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-900 text-xs font-semibold text-white">03</span>
                                <h3 class="text-base font-semibold text-zinc-900">Запуск и сервис</h3>
                            </div>
                            <p class="mt-2 text-sm text-zinc-600">Помогаем с вводом в эксплуатацию и обучением.</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Сервис</p>
                        <p class="mt-3 text-lg font-semibold text-zinc-900">Пуско-наладка</p>
                        <p class="mt-2 text-sm text-zinc-600">Готовим оборудование к работе и фиксируем параметры.</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Обучение</p>
                        <p class="mt-3 text-lg font-semibold text-zinc-900">Передача знаний</p>
                        <p class="mt-2 text-sm text-zinc-600">Проводим инструктаж для операторов и техслужбы.</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Документы</p>
                        <p class="mt-3 text-lg font-semibold text-zinc-900">Полный пакет</p>
                        <p class="mt-2 text-sm text-zinc-600">Сертификаты, паспорта, спецификации и КП.</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-mono uppercase tracking-[0.3em] text-zinc-500">Склад</p>
                        <p class="mt-3 text-lg font-semibold text-zinc-900">Быстрые отгрузки</p>
                        <p class="mt-2 text-sm text-zinc-600">Помогаем выбрать альтернативы, если сроки критичны.</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="relative overflow-hidden bg-zinc-950 text-white">
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute left-1/2 top-0 h-64 w-64 -translate-x-1/2 rounded-full bg-sky-500/20 blur-3xl"></div>
        </div>
        <div class="relative mx-auto flex max-w-7xl flex-col gap-8 px-4 py-14 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex max-w-2xl flex-col gap-3">
                <p class="text-xs font-mono uppercase tracking-[0.3em] text-white/60">Связаться</p>
                <h2 class="text-3xl font-semibold">Нужна консультация?</h2>
                <p class="text-pretty text-sm text-white/70">
                    Оставьте запрос — подготовим конфигурацию, сроки и коммерческое предложение.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <flux:button variant="primary" type="button">Оставить запрос</flux:button>
                <flux:button variant="ghost" type="button" class="text-white">Скачать бриф</flux:button>
            </div>
        </div>
    </section>
</x-layouts.app>
