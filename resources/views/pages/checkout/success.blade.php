<x-layouts.app :title="'Заказ оформлен'">
    @php($ecommercePurchase = session('ecommerce.purchase'))
    @if (is_array($ecommercePurchase) && $ecommercePurchase !== [])
        <script type="application/json" data-ecommerce-purchase>{!! json_encode($ecommercePurchase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif

    <div class="bg-zinc-200 py-14">
        <div class="mx-auto w-full max-w-3xl border border-zinc-200 bg-white p-10 text-center">
            <h1 class="text-3xl font-bold text-black">Заказ принят</h1>
            <p class="mt-4 text-base text-zinc-700">Номер заказа: <span class="font-semibold">{{ $orderNumber }}</span></p>
            @auth
                @php([$od, $os] = array_pad(explode('/', (string) ($orderNumber ?? ''), 2), 2, null))
                @if ($od && $os)
                    <a
                        href="{{ route('user.orders.show', ['date' => $od, 'seq' => $os]) }}"
                        class="mt-4 inline-flex h-11 items-center border border-brand-red px-5 text-sm font-semibold text-brand-red hover:bg-brand-red/5"
                    >
                        Посмотреть заказ
                    </a>
                @endif
            @endauth
            <a href="{{ route('home') }}" class="mt-8 inline-flex h-11 items-center bg-brand-green px-5 text-sm font-semibold text-white hover:bg-[#1c7731]">
                На главную
            </a>
        </div>
    </div>
</x-layouts.app>
