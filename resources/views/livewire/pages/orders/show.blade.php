@php
    $status = $order->status?->value ?? null;
    $payment = $order->payment_status?->value ?? null;

    $statusColor = match ($status) {
        'submitted' => 'border-brand-green/30 bg-brand-green/10 text-black',
        'processing' => 'border-brand-red/30 bg-brand-red/10 text-black',
        'completed' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        'cancelled' => 'border-zinc-300 bg-zinc-100 text-zinc-600',
        default => 'border-zinc-300 bg-zinc-100 text-zinc-600',
    };

    $paymentColor = match ($payment) {
        'awaiting' => 'border-amber-300 bg-amber-50 text-amber-700',
        'paid' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        default => 'border-zinc-300 bg-zinc-100 text-zinc-600',
    };
@endphp

<section class="bg-zinc-200 py-10 flex-1">
    <div class="mx-auto w-full max-w-6xl px-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-black">
                    Заказ {{ $order->order_number ?? '#'.$order->id }}
                </h1>
                <p class="mt-1 text-sm text-zinc-600">от {{ optional($order->created_at)->format('d.m.Y H:i') }}</p>
            </div>
            <a href="{{ route('user.orders.index') }}" class="text-sm font-semibold text-brand-red hover:text-brand-red/80">
                К списку заказов
            </a>
        </div>

        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="border border-zinc-300 bg-white p-4">
                <div class="text-xs text-zinc-500">Статус заказа</div>
                <div class="mt-2">
                    <span class="inline-flex items-center border px-2 py-1 text-xs {{ $statusColor }}">
                        {{ \Lang::has('order.status.'.$status) ? __('order.status.'.$status) : ($status ?: '—') }}
                    </span>
                </div>
            </div>
            <div class="border border-zinc-300 bg-white p-4">
                <div class="text-xs text-zinc-500">Статус оплаты</div>
                <div class="mt-2">
                    <span class="inline-flex items-center border px-2 py-1 text-xs {{ $paymentColor }}">
                        {{ \Lang::has('order.payment.'.$payment) ? __('order.payment.'.$payment) : ($payment ?: '—') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 border border-zinc-300 bg-white">
                <div class="border-b border-zinc-200 px-4 py-3 text-lg font-semibold text-black">
                    Состав заказа
                </div>

                <div class="divide-y divide-zinc-200">
                    @forelse ($order->items as $item)
                        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div>
                                @if ($item->product?->slug)
                                    <a
                                        href="{{ route('product.show', $item->product->slug) }}"
                                        class="font-semibold text-black hover:text-brand-red"
                                    >
                                        {{ $item->name ?? ($item->product?->name ?? 'Товар') }}
                                    </a>
                                @else
                                    <div class="font-semibold text-black">
                                        {{ $item->name ?? ($item->product?->name ?? 'Товар') }}
                                    </div>
                                @endif
                                <div class="mt-1 text-sm text-zinc-600">
                                    {{ (int) $item->quantity }} шт. x {{ price($item->price_amount ?? 0) }}
                                </div>
                            </div>
                            <div class="text-right text-lg font-bold text-black">
                                {{ price($item->total_amount ?? 0) }}
                            </div>
                        </div>
                    @empty
                        <div class="p-4 text-zinc-600">Товары не найдены.</div>
                    @endforelse
                </div>
            </div>

            <aside class="h-max border border-zinc-300 bg-white p-4">
                <h2 class="text-lg font-semibold text-black">Итого</h2>

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-zinc-600">Товары</dt>
                        <dd class="font-semibold text-black">{{ price($order->items_subtotal ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-zinc-600">Скидка</dt>
                        <dd class="font-semibold text-black">-{{ price($order->discount_total ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-zinc-600">Доставка</dt>
                        <dd class="font-semibold text-black">{{ price($order->shipping_total ?? 0) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-2 text-base">
                        <dt class="font-semibold text-black">К оплате</dt>
                        <dd class="text-xl font-bold text-black">{{ price($order->grand_total ?? 0) }}</dd>
                    </div>
                </dl>
            </aside>
        </div>
    </div>
</section>
