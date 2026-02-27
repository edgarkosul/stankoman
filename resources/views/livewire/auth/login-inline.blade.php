<div>
    @if ($open)
        <div
            class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-6"
            wire:click="close"
            x-data
            x-on:keydown.escape.window="$wire.close()"
            wire:key="login-inline-modal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inline-login-title"
                class="relative w-full max-w-md bg-white p-8 shadow-xl dark:bg-zinc-800"
                wire:click.stop
            >
                <button
                    type="button"
                    wire:click="close"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white"
                    aria-label="{{ __('Close') }}"
                >
                    <x-icon name="x" class="size-5" />
                </button>

                <div class="flex flex-col gap-6">
                    <div class="text-center">
                        <h2 id="inline-login-title" class="text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Log in to your account') }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Enter your email and password below to log in') }}
                        </p>
                    </div>

                    <x-auth-session-status class="text-center" :status="session('status')" />

                    <form method="POST" action="{{ route('login', absolute: false) }}" wire:submit="login" class="flex flex-col gap-5">
                        @csrf

                        <div class="flex flex-col gap-1.5">
                            <label for="inline-login-email" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Email address') }}
                            </label>
                            <input
                                wire:model="email"
                                name="email"
                                id="inline-login-email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                placeholder="email@example.com"
                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            />
                            @error('email')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <label for="inline-login-password" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                    {{ __('Password') }}
                                </label>
                                <button type="button" class="cursor-pointer text-sm font-medium text-brand-green hover:underline" wire:click="openForgotPasswordModal">
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
                                    class="h-11 w-full border border-zinc-300 bg-white px-3 pr-16 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-3 my-auto h-fit cursor-pointer text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-300 dark:hover:text-zinc-100"
                                    :aria-label="showPassword ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                                    data-test="login-inline-toggle-password"
                                >
                                    <span x-text="showPassword ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                                </button>
                            </div>
                            @error('password')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <label for="inline-login-remember" class="inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                            <input
                                id="inline-login-remember"
                                type="checkbox"
                                wire:model="remember"
                                class="size-4 border-zinc-300 text-brand-green focus:ring-brand-green dark:border-zinc-600 dark:bg-zinc-900"
                            />
                            <span>{{ __('Remember me') }}</span>
                        </label>

                        <button
                            type="submit"
                            class="inline-flex h-11 w-full items-center justify-center  bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40 disabled:cursor-not-allowed disabled:opacity-60"
                            data-test="login-inline-button"
                        >
                            {{ __('Log in') }}
                        </button>
                    </form>

                    <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse dark:text-zinc-400">
                        <span>{{ __('Don\'t have an account?') }}</span>
                        <button type="button" class="cursor-pointer font-medium text-brand-green hover:underline" wire:click="openRegisterModal">
                            {{ __('Sign up') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
