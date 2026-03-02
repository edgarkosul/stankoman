<div>
    @if ($open)
        <div
            class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-6"
            wire:click="close"
            x-data
            x-on:keydown.escape.window="$wire.close()"
            wire:key="verify-email-inline-modal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inline-verify-email-title"
                class="relative w-full max-w-md bg-white p-8 shadow-xl dark:bg-zinc-800"
                wire:click.stop
            >
                <button
                    type="button"
                    wire:click="close"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white"
                    aria-label="{{ __('Закрыть') }}"
                >
                    <x-icon name="x" class="size-5" />
                </button>

                <div class="flex flex-col gap-6">
                    <div class="text-center">
                        <h2 id="inline-verify-email-title" class="text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Подтвердите адрес электронной почты') }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Для продолжения подтвердите адрес электронной почты :email.', ['email' => auth()->user()?->email ?? '']) }}
                        </p>

                        @if (! $linkSent)
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Нажмите кнопку ниже, чтобы отправить письмо с ссылкой для подтверждения.') }}
                            </p>
                        @endif
                    </div>

                    @if ($linkSent)
                        <p class="text-center text-sm font-medium text-green-600 dark:text-green-400">
                            {{ __('Новая ссылка для подтверждения отправлена на вашу электронную почту.') }}
                        </p>
                    @endif

                    @error('verification')
                        <p class="text-center text-sm font-medium text-red-600 dark:text-red-400">
                            {{ $message }}
                        </p>
                    @enderror

                    <div class="flex flex-col gap-3">
                        <button
                            type="button"
                            class="inline-flex h-11 w-full items-center justify-center bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40 disabled:cursor-not-allowed disabled:opacity-60"
                            wire:click="resendVerificationNotification"
                            wire:loading.attr="disabled"
                            wire:target="resendVerificationNotification"
                            data-test="verify-email-inline-resend-button"
                        >
                            <span wire:loading.remove wire:target="resendVerificationNotification">
                                {{ $linkSent ? __('Отправить письмо повторно') : __('Отправить письмо') }}
                            </span>
                            <span wire:loading wire:target="resendVerificationNotification">
                                {{ __('Отправка...') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="inline-flex h-11 w-full items-center justify-center border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300/70 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:focus-visible:ring-zinc-500/60"
                            wire:click="continueToApp"
                            data-test="verify-email-inline-continue-button"
                        >
                            {{ __('Я уже подтвердил почту') }}
                        </button>

                        <button
                            type="button"
                            class="inline-flex h-11 w-full items-center justify-center border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300/70 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:focus-visible:ring-zinc-500/60"
                            wire:click="logout"
                            data-test="verify-email-inline-logout-button"
                        >
                            {{ __('Выйти') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
