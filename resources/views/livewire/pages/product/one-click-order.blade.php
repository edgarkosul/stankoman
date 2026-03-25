<div
    x-data="{
        syncScrollLock(value) {
            document.documentElement.classList.toggle('overflow-hidden', value);
            document.body.classList.toggle('overflow-hidden', value);
        },
    }"
    x-init="syncScrollLock($wire.isOpen); $watch('$wire.isOpen', value => syncScrollLock(value))"
>
    @if ($isOpen)
        <div
            class="fixed inset-0 z-[70] bg-black/50 p-4 md:p-6"
            wire:click="close"
            x-on:keydown.escape.window="$wire.close()"
            wire:key="one-click-order-modal"
        >
            <div class="flex min-h-full items-center justify-center">
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="one-click-order-title"
                    class="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden bg-white shadow-xl md:max-h-[calc(100dvh-3rem)]"
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
                                    <h2 id="one-click-order-title" class="text-3xl font-semibold text-zinc-900">
                                        Заказ отправлен
                                    </h2>
                                    <p class="text-base text-zinc-700">
                                        Менеджер свяжется с вами для подтверждения деталей.
                                    </p>
                                    @if ($submittedOrderNumber)
                                        <p class="text-sm text-zinc-600">
                                            Номер заказа: <span class="font-semibold text-zinc-900">{{ $submittedOrderNumber }}</span>
                                        </p>
                                    @endif
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
                                    <h2 id="one-click-order-title" class="text-3xl font-semibold text-zinc-900">
                                        Заказать продукт
                                    </h2>
                                    <p class="text-sm text-zinc-600">
                                        Заполните форму, и менеджер свяжется с вами для подтверждения заказа.
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
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Ваше имя *</label>
                                        <input
                                            type="text"
                                            wire:model.blur="customerName"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Телефон *</label>
                                        <input
                                            type="tel"
                                            inputmode="tel"
                                            autocomplete="tel"
                                            data-phone-mask="ru"
                                            placeholder="+7 (___) ___-__-__"
                                            wire:model.blur="customerPhone"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Электронная почта</label>
                                        <input
                                            type="email"
                                            inputmode="email"
                                            autocomplete="email"
                                            wire:model.blur="customerEmail"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    <div class="md:col-span-2">
                                        <div class="mb-1 block text-sm font-medium text-zinc-900">Продукт</div>
                                        <div class="min-h-11 border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-900">
                                            {{ $product['name'] ?? 'Товар' }}
                                        </div>
                                    </div>

                                    @if (filled($product['brand'] ?? null))
                                        <div class="md:col-span-2">
                                            <div class="mb-1 block text-sm font-medium text-zinc-900">Бренд</div>
                                            <div class="min-h-11 border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-900">
                                                {{ $product['brand'] }}
                                            </div>
                                        </div>
                                    @endif

                                    <div class="md:col-span-2">
                                        <div class="mb-1 block text-sm font-medium text-zinc-900">Количество</div>
                                        <div class="min-h-11 border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-900">
                                            {{ $quantity }} шт.
                                        </div>
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Сообщение</label>
                                        <textarea
                                            wire:model.blur="shippingComment"
                                            rows="4"
                                            class="w-full border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Страна *</label>
                                        <input
                                            type="text"
                                            wire:model.blur="shippingCountry"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-900">Регион</label>
                                        <input
                                            type="text"
                                            wire:model.blur="shippingRegion"
                                            class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                        />
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="inline-flex items-start gap-3 text-sm text-zinc-900">
                                            <input
                                                type="checkbox"
                                                wire:model.live="acceptTerms"
                                                class="mt-1 size-4 border-zinc-300 text-brand-green focus:ring-brand-green"
                                            />
                                            <span>
                                                Я согласен на
                                                <a href="{{ route('page.show', 'terms') }}"
                                                    class="font-semibold text-brand-green underline"
                                                    target="_blank"
                                                    rel="noopener">
                                                    обработку персональных данных
                                                </a>
                                            </span>
                                        </label>
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
