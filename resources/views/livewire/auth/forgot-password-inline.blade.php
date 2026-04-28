<div>
    @if ($open)
        <div
            class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-6"
            wire:click="close"
            x-data
            x-on:keydown.escape.window="$wire.close()"
            wire:key="forgot-inline-modal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inline-forgot-title"
                class="relative w-full max-w-md bg-white p-8 shadow-xl"
                wire:click.stop
            >
                <button
                    type="button"
                    wire:click="close"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                    aria-label="{{ __('Close') }}"
                >
                    <x-icon name="x" class="size-5" />
                </button>

                <div class="flex flex-col gap-6">
                    <div class="text-center">
                        <h2 id="inline-forgot-title" class="text-3xl font-semibold text-zinc-900">
                            {{ __('Forgot password') }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-600">
                            {{ __('Enter your email to receive a password reset link') }}
                        </p>
                    </div>

                    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-5">
                        <div class="flex flex-col gap-1.5">
                            <label for="inline-forgot-email" class="text-sm font-medium text-zinc-700">
                                {{ __('Email Address') }}
                            </label>
                            <input
                                wire:model="email"
                                id="inline-forgot-email"
                                name="email"
                                type="email"
                                required
                                autofocus
                                placeholder="email@example.com"
                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                            />
                            @error('email')
                                <p class="text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex h-11 w-full items-center justify-center bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40 disabled:cursor-not-allowed disabled:opacity-60"
                            data-test="forgot-password-inline-button"
                        >
                            {{ __('Email password reset link') }}
                        </button>
                    </form>

                    <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse">
                        <span>{{ __('Or, return to') }}</span>
                        <button type="button" class="cursor-pointer font-medium text-brand-green hover:underline" wire:click="openLoginModal">
                            {{ __('log in') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
