<div
    x-data="{
        syncScrollLock(value) {
            document.documentElement.classList.toggle('overflow-hidden', value);
            document.body.classList.toggle('overflow-hidden', value);
        },
    }"
    x-init="$watch('$wire.isOpen', value => syncScrollLock(value))"
>
    <button
        type="button"
        wire:click="open"
        wire:loading.attr="disabled"
        wire:target="open"
        class="inline-flex h-12 w-full items-center justify-center gap-2 border border-zinc-300 bg-white px-6 text-base font-medium text-zinc-700 shadow-sm transition hover:border-brand-green hover:text-brand-green"
    >
        <x-icon name="phone" class="size-5 shrink-0 [&_.icon-base]:text-current [&_.icon-accent]:text-brand-red" />
        <span>{{ $this->buttonLabel() }}</span>
    </button>

    {{--
        Модалка уезжает в body: кнопка стоит внутри карточки товара (а потом
        и в панели чата), у которых свой контекст наложения, и z-index оттуда
        не перекрыл бы ни галерею, ни шапку. OneClickOrder этого не нужно —
        он смонтирован в корне лэйаута.
    --}}
    <template x-teleport="body">
    <div>
    @if ($isOpen)
        <div
            class="fixed inset-0 z-[70] bg-black/50 p-4 md:p-6"
            wire:click="close"
            x-on:keydown.escape.window="$wire.close()"
            wire:key="request-callback-modal"
        >
            <div class="flex min-h-full items-center justify-center">
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="{{ $this->getId() }}-title"
                    class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-xl flex-col overflow-hidden bg-white text-left shadow-xl md:max-h-[calc(100dvh-3rem)]"
                    wire:click.stop
                >
                    <button
                        type="button"
                        wire:click="close"
                        class="absolute right-3 top-3 z-10 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                        aria-label="Закрыть"
                    >
                        <x-icon name="x" class="size-5" />
                    </button>

                    <div class="min-h-0 overflow-y-auto overscroll-contain p-6 md:p-8">
                        @if ($submitted)
                            <div class="flex flex-col gap-6">
                                <div class="space-y-2">
                                    <h2 id="{{ $this->getId() }}-title" class="text-3xl font-semibold text-zinc-900">
                                        Заявка отправлена
                                    </h2>
                                    {{-- Обещаем ровно то, что сделаем: при почтовом канале менеджер пишет, а не звонит. --}}
                                    <p class="text-base text-zinc-700">
                                        {{ $this->wantsEmail() ? 'Менеджер ответит вам на почту.' : 'Менеджер перезвонит вам в рабочее время.' }}
                                    </p>
                                </div>

                                <div class="flex justify-end">
                                    <button
                                        type="button"
                                        wire:click="close"
                                        class="inline-flex h-11 items-center justify-center bg-brand-green px-5 text-sm font-semibold text-white transition hover:bg-[#1c7731]"
                                    >
                                        Закрыть
                                    </button>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-col gap-6">
                                <div class="space-y-2">
                                    <h2 id="{{ $this->getId() }}-title" class="text-3xl font-semibold text-zinc-900">
                                        {{ $this->wantsEmail() ? 'Менеджер свяжется с вами' : 'Обратный звонок' }}
                                    </h2>
                                    <p class="text-sm text-zinc-600">
                                        {{ $this->wantsEmail()
                                            ? 'Оставьте почту — менеджер ответит письмом.'
                                            : 'Оставьте телефон — менеджер перезвонит и ответит на вопросы.' }}
                                    </p>
                                </div>

                                @if ($errors->any())
                                    <div class="border border-brand-red bg-red-50 p-3 text-sm text-brand-red">
                                        <ul class="space-y-1">
                                            @foreach ($errors->all() as $error)
                                                <li>{{ $error }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <form wire:submit="submit" class="grid gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <label for="{{ $this->getId() }}-name" class="mb-1 block text-sm font-medium text-zinc-900">Ваше имя *</label>
                                        <input
                                            id="{{ $this->getId() }}-name"
                                            type="text"
                                            autocomplete="name"
                                            wire:model.blur="customerName"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    {{-- Первым идёт контакт, которым магазин ответит, — он и обязателен. --}}
                                    @foreach ($this->wantsEmail() ? ['email', 'phone'] : ['phone', 'email'] as $contactField)
                                        @if ($contactField === 'phone')
                                            <div>
                                                <label for="{{ $this->getId() }}-phone" class="mb-1 block text-sm font-medium text-zinc-900">
                                                    {{ $this->wantsEmail() ? 'Телефон, если удобнее звонок' : 'Телефон *' }}
                                                </label>
                                                <input
                                                    id="{{ $this->getId() }}-phone"
                                                    type="tel"
                                                    inputmode="tel"
                                                    autocomplete="tel"
                                                    data-phone-mask="ru"
                                                    placeholder="+7 (___) ___-__-__"
                                                    wire:model.blur="customerPhone"
                                                    class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                                />
                                            </div>
                                        @else
                                            <div>
                                                <label for="{{ $this->getId() }}-email" class="mb-1 block text-sm font-medium text-zinc-900">
                                                    {{ $this->wantsEmail() ? 'Электронная почта *' : 'Почта, если удобнее письмом' }}
                                                </label>
                                                <input
                                                    id="{{ $this->getId() }}-email"
                                                    type="email"
                                                    inputmode="email"
                                                    autocomplete="email"
                                                    wire:model.blur="customerEmail"
                                                    class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                                />
                                            </div>
                                        @endif
                                    @endforeach

                                    @if (filled($productName))
                                        <div class="md:col-span-2">
                                            <div class="mb-1 block text-sm font-medium text-zinc-900">Товар</div>
                                            <div class="min-h-11 border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-900">
                                                {{ $productName }}
                                            </div>
                                        </div>
                                    @endif

                                    <div @class(['md:col-span-2' => $this->wantsEmail()])>
                                        <label for="{{ $this->getId() }}-city" class="mb-1 block text-sm font-medium text-zinc-900">Город</label>
                                        <input
                                            id="{{ $this->getId() }}-city"
                                            type="text"
                                            autocomplete="address-level2"
                                            wire:model.blur="city"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    {{-- Время звонка спрашиваем только там, где звонок обещан. --}}
                                    @unless ($this->wantsEmail())
                                        <div>
                                            <label for="{{ $this->getId() }}-call-time" class="mb-1 block text-sm font-medium text-zinc-900">Удобное время звонка</label>
                                            <input
                                                id="{{ $this->getId() }}-call-time"
                                                type="text"
                                                placeholder="Например, после 14:00"
                                                wire:model.blur="callTime"
                                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                            />
                                        </div>
                                    @endunless

                                    <div class="md:col-span-2">
                                        <label for="{{ $this->getId() }}-comments" class="mb-1 block text-sm font-medium text-zinc-900">Вопрос или комментарий</label>
                                        <textarea
                                            id="{{ $this->getId() }}-comments"
                                            wire:model.blur="comments"
                                            rows="4"
                                            class="w-full border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        ></textarea>
                                    </div>

                                    <div class="md:col-span-2 text-sm text-zinc-600">
                                        Нажимая кнопку «Отправить», вы соглашаетесь с
                                        <a href="{{ route('page.show', 'terms') }}"
                                            class="font-semibold text-brand-green underline"
                                            target="_blank"
                                            rel="noopener">
                                            Пользовательским соглашением
                                        </a>
                                        и
                                        <a href="{{ route('page.show', 'privacy') }}"
                                            class="font-semibold text-brand-green underline"
                                            target="_blank"
                                            rel="noopener">
                                            Политикой обработки персональных данных
                                        </a>.
                                    </div>

                                    <div class="md:col-span-2 flex justify-end pt-2">
                                        <button
                                            type="submit"
                                            wire:loading.attr="disabled"
                                            wire:target="submit"
                                            class="inline-flex h-11 items-center justify-center bg-brand-green px-6 text-sm font-semibold text-white transition hover:bg-[#1c7731] disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            <span wire:loading.remove wire:target="submit">Отправить</span>
                                            <span wire:loading wire:target="submit">Отправка...</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
    </div>
    </template>
</div>
