<div x-data="{ open: @entangle('open').live }" x-cloak>
    <template x-if="open">
        <div
            class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-6"
            x-init="$nextTick(() => $refs.inlineLoginEmail?.focus())"
            x-on:click.self="$wire.close()"
            x-on:keydown.escape.window="$wire.close()"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inline-login-title"
                class="relative w-full max-w-md bg-white p-8 shadow-xl"
            >
                <button
                    type="button"
                    @click="$wire.close()"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                    aria-label="{{ __('Close') }}"
                >
                    <x-icon name="x" class="size-5" />
                </button>

                <div class="flex flex-col gap-6">
                    <div class="text-center">
                        <h2 id="inline-login-title" class="text-3xl font-semibold text-zinc-900">
                            {{ __('Log in to your account') }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-600">
                            {{ __('Enter your email and password below to log in') }}
                        </p>
                    </div>

                    <x-auth-session-status class="text-center" :status="session('status')" />

                    <form wire:submit="login" class="flex flex-col gap-5">
                        <div class="flex flex-col gap-1.5">
                            <label for="inline-login-email" class="text-sm font-medium text-zinc-700">
                                {{ __('Email address') }}
                            </label>
                            <input
                                x-ref="inlineLoginEmail"
                                wire:model="email"
                                name="email"
                                id="inline-login-email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                placeholder="email@example.com"
                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                            />
                            @error('email')
                                <p class="text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <label for="inline-login-password" class="text-sm font-medium text-zinc-700">
                                    {{ __('Password') }}
                                </label>
                                <button
                                    type="button"
                                    class="cursor-pointer text-sm font-medium text-brand-green hover:underline"
                                    @click="$wire.openForgotPasswordModal()"
                                >
                                    {{ __('Forgot your password?') }}
                                </button>
                            </div>

                            <div x-data="{ showPassword: false }" class="relative">
                                <input
                                    wire:model="password"
                                    name="password"
                                    id="inline-login-password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="current-password"
                                    placeholder="{{ __('Password') }}"
                                    class="h-11 w-full border border-zinc-300 bg-white px-3 pr-16 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-3 my-auto h-fit cursor-pointer text-xs font-medium text-zinc-500 hover:text-zinc-700"
                                    :aria-label="showPassword ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                                    data-test="login-inline-toggle-password"
                                >
                                    <span x-text="showPassword ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                                </button>
                            </div>
                            @error('password')
                                <p class="text-sm font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <label for="inline-login-remember" class="inline-flex items-center gap-2 text-sm text-zinc-700">
                            <input
                                id="inline-login-remember"
                                type="checkbox"
                                wire:model="remember"
                                class="size-4 border-zinc-300 text-brand-green focus:ring-brand-green"
                            />
                            <span>{{ __('Remember me') }}</span>
                        </label>

                        <button
                            type="submit"
                            class="inline-flex h-11 w-full items-center justify-center bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40 disabled:cursor-not-allowed disabled:opacity-60"
                            data-test="login-inline-button"
                        >
                            {{ __('Log in') }}
                        </button>
                    </form>

                    <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse">
                        <span>{{ __('Don\'t have an account?') }}</span>
                        <button type="button" class="cursor-pointer font-medium text-brand-green hover:underline" @click="$wire.openRegisterModal()">
                            {{ __('Sign up') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
