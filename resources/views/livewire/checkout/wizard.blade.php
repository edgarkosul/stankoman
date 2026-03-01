<div class="bg-zinc-200 flex-1 py-10">
    <div class="mx-auto grid w-full max-w-7xl gap-4 px-4 lg:grid-cols-[1fr_360px]">
        <section class="border border-zinc-200 bg-white p-6">
            <h1 class="text-3xl font-bold text-black">Оформление заказа</h1>

            <div class="mt-5 grid grid-cols-3 gap-2 text-sm">
                @foreach ([1 => 'Контакты', 2 => 'Доставка', 3 => 'Подтверждение'] as $stepNumber => $stepName)
                    <div class="flex items-center gap-2">
                        <span
                            class="grid h-8 w-8 place-items-center border text-sm font-semibold {{ $currentStep >= $stepNumber ? 'border-brand-green bg-brand-green text-white' : 'border-zinc-300 bg-white text-zinc-600' }}">
                            {{ $stepNumber }}
                        </span>
                        <span
                            class="{{ $currentStep >= $stepNumber ? 'text-brand-green' : 'text-zinc-600' }}">{{ $stepName }}</span>
                    </div>
                @endforeach
            </div>

            @if ($errors->any())
                <div class="mt-5 border border-brand-red bg-red-50 p-3 text-sm text-brand-red">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 space-y-5">
                @if ($currentStep === 1)
                    <div class="grid gap-4 md:grid-cols-2">
                        @guest
                            <div class="md:col-span-2 border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                                Если вы уже зарегистрированы,
                                <button type="button" wire:click="openLoginModal"
                                    class="font-semibold text-brand-green underline hover:text-brand-red">
                                    Войдите
                                </button>
                                и поля заказа заполнятся автоматически.
                            </div>
                        @endguest

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-black">ФИО</label>
                            <input type="text" wire:model.blur="contact.customer_name"
                                class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-black">Телефон</label>
                            <input type="tel" inputmode="tel" autocomplete="tel" data-phone-mask="ru"
                                placeholder="+7 (___) ___-__-__" wire:model.blur="contact.customer_phone"
                                class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            <p class="mt-1 text-xs text-zinc-600">Формат: +7 (999) 123-45-67.</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-black">Email</label>
                            <input type="email" inputmode="email" autocomplete="email"
                                wire:model.blur="contact.customer_email"
                                class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                        </div>

                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-black">
                                <input type="checkbox" wire:model.live="contact.is_company"
                                    class="h-4 w-4 border border-brand-green" />
                                <span>Юридическое лицо или ИП</span>
                            </label>
                        </div>

                        @guest
                            <div class="md:col-span-2">
                                <label class="inline-flex items-center gap-2 text-sm font-medium text-black">
                                    <input type="checkbox" wire:model.live="contact.create_account"
                                        class="h-4 w-4 border border-brand-green" />
                                    <span>Создать личный кабинет и сохранить заказ в истории</span>
                                </label>
                            </div>
                        @endguest
                        @auth
                            <p class="md:col-span-2 text-xs text-zinc-600">Вы авторизованы: заказ автоматически сохранится в
                                истории личного кабинета.</p>
                        @endauth

                        @if ((bool) ($contact['is_company'] ?? false))
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">ИНН</label>
                                <input type="text" inputmode="numeric" maxlength="12" wire:model.live="contact.inn"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">Название компании</label>
                                <input type="text" wire:model.blur="contact.company_name"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>


                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">КПП</label>
                                <input type="text" inputmode="numeric" maxlength="9" wire:model.live="contact.kpp"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                        @endif
                    </div>
                @endif

                @if ($currentStep === 2)
                    <div class="space-y-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-black">Способ доставки</label>
                            <div class="border border-brand-green p-3 text-sm font-medium text-black">Доставка</div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">Город</label>
                                <input type="text" wire:model.blur="delivery.shipping_city"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">Улица</label>
                                <input type="text" wire:model.blur="delivery.shipping_street"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">Дом/офис</label>
                                <input type="text" wire:model.blur="delivery.shipping_house"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-black">Индекс</label>
                                <input type="text" wire:model.blur="delivery.shipping_postcode"
                                    class="h-11 w-full border border-brand-green bg-white px-3 text-sm text-black outline-none focus:border-brand-red" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-sm font-medium text-black">Комментарий</label>
                                <textarea wire:model.blur="delivery.shipping_comment" rows="3"
                                    class="w-full border border-brand-green bg-white px-3 py-2 text-sm text-black outline-none focus:border-brand-red"></textarea>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($currentStep === 3)
                    <div class="space-y-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-black">Способ оплаты</label>
                            <div class="grid gap-2 md:grid-cols-3">
                                <label
                                    class="flex items-center gap-2 border border-brand-green p-3 text-sm text-black">
                                    <input type="radio" wire:model.live="review.payment_method" value="cash"
                                        class="h-4 w-4 border border-brand-green" />
                                    <span>Наличные</span>
                                </label>
                                <label
                                    class="flex items-center gap-2 border border-brand-green p-3 text-sm text-black">
                                    <input type="radio" wire:model.live="review.payment_method"
                                        value="bank_transfer" class="h-4 w-4 border border-brand-green" />
                                    <span>Безнал</span>
                                </label>
                                <label
                                    class="flex items-center gap-2 border border-brand-green p-3 text-sm text-black">
                                    <input type="radio" wire:model.live="review.payment_method" value="credit"
                                        class="h-4 w-4 border border-brand-green" />
                                    <span>Кредит</span>
                                </label>
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-2 text-sm text-black">
                            <input type="checkbox" wire:model.live="review.accept_terms"
                                class="h-4 w-4 border border-brand-green" />
                            <span>
                                Принимаю
                                <a href="{{ route('page.show', 'terms') }}"
                                    class="font-semibold text-brand-green underline" target="_blank"
                                    rel="noopener">пользовательское соглашение</a>
                            </span>
                        </label>
                    </div>
                @endif
            </div>

            <div class="mt-8 flex items-center justify-between gap-3">
                @if ($currentStep > 1)
                    <button type="button" wire:click="previous"
                        class="h-11 border border-brand-green px-4 text-sm font-semibold text-brand-green hover:bg-zinc-100">
                        Назад
                    </button>
                @else
                    <span></span>
                @endif

                @if ($currentStep < 3)
                    <button type="button" wire:click="next"
                        class="h-11 bg-brand-green px-5 text-sm font-semibold text-white hover:bg-[#1c7731]">
                        Далее
                    </button>
                @else
                    <button type="button" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm"
                        class="h-11 bg-brand-green px-5 text-sm font-semibold text-white hover:bg-[#1c7731] disabled:opacity-60">
                        <span wire:loading.remove wire:target="confirm">Отправить заказ</span>
                        <span wire:loading wire:target="confirm">Отправка...</span>
                    </button>
                @endif
            </div>
        </section>

        <aside class="h-max border border-zinc-200 bg-white p-5">
            <h2 class="text-xl font-semibold text-black">Ваш заказ</h2>

            <div class="mt-4 space-y-3 border-b border-zinc-200 pb-4">
                @forelse ($items as $item)
                    @php
                        $product = $item->product;
                        $qty = (int) $item->quantity;
                        $basePrice = (int) ($product?->price_int ?? ($product?->price_amount ?? 0));
                        $discountPrice = $product?->discount;
                        $discountPrice = $discountPrice === null ? null : (int) $discountPrice;
                        $applyDiscounts = auth()->check() || (bool) ($contact['create_account'] ?? false);
                        $linePrice =
                            $applyDiscounts &&
                            $discountPrice !== null &&
                            $discountPrice > 0 &&
                            $discountPrice < $basePrice
                                ? $discountPrice
                                : $basePrice;
                    @endphp
                    <div wire:key="checkout-item-{{ $item->id }}" class="border border-zinc-200 p-3">
                        <div class="text-sm font-semibold text-black">{{ $product?->name ?? 'Товар' }}</div>
                        <div class="mt-1 text-xs text-zinc-600">{{ $qty }} шт. x {{ price($linePrice) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-600">Корзина пуста.</p>
                @endforelse
            </div>

            <dl class="mt-4 space-y-2 text-sm text-black">
                <div class="flex justify-between gap-2">
                    <dt>Товары</dt>
                    <dd>{{ price($totals['items_subtotal']) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Скидка</dt>
                    <dd>-{{ price($totals['discount_total']) }}</dd>
                </div>
                <div class="flex justify-between gap-2 border-t border-zinc-200 pt-2 text-base font-semibold">
                    <dt>Итого</dt>
                    <dd>{{ price($totals['grand_total']) }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-zinc-600">
                Стоимость доставки уточнит менеджер после оформления заказа.
            </p>
        </aside>
    </div>
</div>
