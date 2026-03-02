<x-layouts::auth>
    <div class="mt-4 flex flex-col gap-6">
        <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('Подтвердите адрес электронной почты, перейдя по ссылке, которую мы только что отправили вам на email.') }}
        </p>

        @if (session('status') == 'verification-link-sent')
            <p class="text-center text-sm font-medium text-green-600 dark:text-green-400">
                {{ __('Новая ссылка для подтверждения отправлена на адрес электронной почты, указанный при регистрации.') }}
            </p>
        @endif

        <div class="flex flex-col items-center gap-3">
            <form method="POST" action="{{ route('verification.send') }}" class="w-full">
                @csrf
                <button
                    type="submit"
                    class="inline-flex h-11 w-full items-center justify-center bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40"
                >
                    {{ __('Отправить письмо повторно') }}
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="cursor-pointer text-sm font-medium text-zinc-700 hover:underline dark:text-zinc-300"
                    data-test="logout-button"
                >
                    {{ __('Выйти') }}
                </button>
            </form>
        </div>
    </div>
</x-layouts::auth>
