@php
    $statusColor = static function (?string $status): string {
        return match ($status) {
            'submitted' => 'border-brand-green/30 bg-brand-green/10 text-black',
            'processing' => 'border-brand-red/30 bg-brand-red/10 text-black',
            'completed' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            'cancelled' => 'border-zinc-300 bg-zinc-100 text-zinc-600',
            default => 'border-zinc-300 bg-zinc-100 text-zinc-600',
        };
    };

    $paymentColor = static function (?string $status): string {
        return match ($status) {
            'awaiting' => 'border-amber-300 bg-amber-50 text-amber-700',
            'paid' => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            default => 'border-zinc-300 bg-zinc-100 text-zinc-600',
        };
    };
@endphp

<section class="bg-zinc-200 py-10">
    <div class="mx-auto w-full max-w-6xl px-6">
        <header class="mb-5 flex items-end justify-between gap-3">
            <h1 class="text-3xl font-bold text-black">Мои заказы</h1>
            <div class="text-sm text-zinc-500">Всего: {{ $orders->total() }}</div>
        </header>

        <div class="mb-4 grid grid-cols-1 gap-3 bg-white p-4 md:grid-cols-5">
            <div>
                <label for="orders-search" class="mb-1 block text-xs font-semibold text-zinc-600">Поиск по номеру</label>
                <input
                    id="orders-search"
                    type="search"
                    placeholder="Например 27-02-26/01"
                    wire:model.live.debounce.500ms="search"
                    class="h-10 w-full border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-brand-red"
                />
            </div>

            <div>
                <label for="orders-status" class="mb-1 block text-xs font-semibold text-zinc-600">Статус</label>
                <select
                    id="orders-status"
                    wire:model.live="status"
                    class="h-10 w-full border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-brand-red"
                >
                    <option value="">Все</option>
                    @foreach ($availableStatuses as $itemStatus)
                        <option value="{{ $itemStatus }}">
                            {{ \Lang::has('order.status.'.$itemStatus) ? __('order.status.'.$itemStatus) : $itemStatus }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="orders-payment" class="mb-1 block text-xs font-semibold text-zinc-600">Оплата</label>
                <select
                    id="orders-payment"
                    wire:model.live="payment"
                    class="h-10 w-full border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-brand-red"
                >
                    <option value="">Все</option>
                    @foreach ($availablePaymentStatuses as $paymentStatus)
                        <option value="{{ $paymentStatus }}">
                            {{ \Lang::has('order.payment.'.$paymentStatus) ? __('order.payment.'.$paymentStatus) : $paymentStatus }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="orders-period" class="mb-1 block text-xs font-semibold text-zinc-600">Период</label>
                <select
                    id="orders-period"
                    wire:model.live="period"
                    class="h-10 w-full border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-brand-red"
                >
                    <option value="">За всё время</option>
                    <option value="30">30 дней</option>
                    <option value="90">3 месяца</option>
                    <option value="365">1 год</option>
                </select>
            </div>

            <div>
                <label for="orders-sort" class="mb-1 block text-xs font-semibold text-zinc-600">Сортировка</label>
                <select
                    id="orders-sort"
                    wire:model.live="sort"
                    class="h-10 w-full border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-brand-red"
                >
                    <option value="date_desc">Дата: новые</option>
                    <option value="date_asc">Дата: старые</option>
                    <option value="total_desc">Сумма: больше</option>
                    <option value="total_asc">Сумма: меньше</option>
                </select>
            </div>
        </div>

        @if ($orders->isEmpty())
            <div class="border border-zinc-300 bg-white p-8 text-center text-zinc-600">
                Заказы не найдены.
            </div>
        @else
            <div class="overflow-x-auto border border-zinc-300 bg-white">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-100 text-left font-semibold text-zinc-600">
                            <th class="px-4 py-3">№ заказа</th>
                            <th class="px-4 py-3">Дата</th>
                            <th class="px-4 py-3">Сумма</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3">Оплата</th>
                            <th class="px-4 py-3 text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @foreach ($orders as $order)
                            @php
                                $status = $order->status?->value ?? null;
                                $payment = $order->payment_status?->value ?? null;
                                $date = optional($order->order_date)->format('d-m-y');
                                $seq = str_pad((string) $order->seq, 2, '0', STR_PAD_LEFT);
                            @endphp
                            <tr class="hover:bg-zinc-50">
                                <td class="px-4 py-3 font-semibold text-black">{{ $order->order_number ?? '#'.$order->id }}</td>
                                <td class="px-4 py-3">{{ optional($order->created_at)->format('d.m.Y') }}</td>
                                <td class="px-4 py-3 font-semibold">{{ price($order->grand_total ?? 0) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center border px-2 py-1 text-xs {{ $statusColor($status) }}">
                                        {{ \Lang::has('order.status.'.$status) ? __('order.status.'.$status) : ($status ?: '—') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center border px-2 py-1 text-xs {{ $paymentColor($payment) }}">
                                        {{ \Lang::has('order.payment.'.$payment) ? __('order.payment.'.$payment) : ($payment ?: '—') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a
                                        href="{{ route('user.orders.show', ['date' => $date, 'seq' => $seq]) }}"
                                        class="text-sm font-semibold text-brand-red hover:text-brand-red/80"
                                    >
                                        Подробнее
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $orders->onEachSide(1)->links() }}
            </div>
        @endif
    </div>
</section>
