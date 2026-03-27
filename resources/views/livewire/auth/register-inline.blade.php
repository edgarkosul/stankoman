<div x-data="{ open: @entangle('open').live }" x-cloak>
    <template x-if="open">
        <div
            class="fixed inset-0 z-[70] grid place-items-center bg-black/50 p-6"
            x-init="$nextTick(() => $refs.inlineRegisterName?.focus())"
            x-on:click.self="$wire.close()"
            x-on:keydown.escape.window="$wire.close()"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="inline-register-title"
                class="relative w-full max-w-md bg-white p-8 shadow-xl dark:bg-zinc-800"
            >
                <button
                    type="button"
                    @click="$wire.close()"
                    class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white"
                    aria-label="{{ __('Close') }}"
                >
                    <x-icon name="x" class="size-5" />
                </button>

                <div class="flex flex-col gap-6">
                    <div class="text-center">
                        <h2 id="inline-register-title" class="text-3xl font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Create an account') }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Enter your details below to create your account') }}
                        </p>
                    </div>

                    <form wire:submit="register" class="flex flex-col gap-5">
                        <div class="flex flex-col gap-1.5">
                            <label for="inline-register-name" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Name') }}
                            </label>
                            <input
                                x-ref="inlineRegisterName"
                                wire:model="name"
                                id="inline-register-name"
                                name="name"
                                type="text"
                                required
                                autofocus
                                autocomplete="name"
                                placeholder="{{ __('Full name') }}"
                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            />
                            @error('name')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="inline-register-email" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Email address') }}
                            </label>
                            <input
                                wire:model="email"
                                id="inline-register-email"
                                name="email"
                                type="email"
                                required
                                autocomplete="email"
                                placeholder="email@example.com"
                                class="h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                            />
                            @error('email')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="inline-register-password" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Password') }}
                            </label>
                            <div x-data="{ showPassword: false }" class="relative">
                                <input
                                    wire:model="password"
                                    id="inline-register-password"
                                    name="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="new-password"
                                    placeholder="{{ __('Password') }}"
                                    class="h-11 w-full border border-zinc-300 bg-white px-3 pr-16 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-3 my-auto h-fit cursor-pointer text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-300 dark:hover:text-zinc-100"
                                    :aria-label="showPassword ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                                    data-test="register-inline-toggle-password"
                                >
                                    <span x-text="showPassword ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                                </button>
                            </div>
                            @error('password')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="inline-register-password-confirmation" class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Confirm password') }}
                            </label>
                            <div x-data="{ showPasswordConfirmation: false }" class="relative">
                                <input
                                    wire:model="password_confirmation"
                                    id="inline-register-password-confirmation"
                                    name="password_confirmation"
                                    :type="showPasswordConfirmation ? 'text' : 'password'"
                                    required
                                    autocomplete="new-password"
                                    placeholder="{{ __('Confirm password') }}"
                                    class="h-11 w-full border border-zinc-300 bg-white px-3 pr-16 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500"
                                />
                                <button
                                    type="button"
                                    @click="showPasswordConfirmation = !showPasswordConfirmation"
                                    class="absolute inset-y-0 right-3 my-auto h-fit cursor-pointer text-xs font-medium text-zinc-500 hover:text-zinc-700 dark:text-zinc-300 dark:hover:text-zinc-100"
                                    :aria-label="showPasswordConfirmation ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                                    data-test="register-inline-toggle-password-confirmation"
                                >
                                    <span x-text="showPasswordConfirmation ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span>
                                </button>
                            </div>
                            @error('password_confirmation')
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            class="inline-flex h-11 w-full items-center justify-center bg-brand-green px-4 text-sm font-semibold text-white transition hover:bg-[#1c7731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green/40 disabled:cursor-not-allowed disabled:opacity-60"
                            data-test="register-inline-button"
                        >
                            {{ __('Create account') }}
                        </button>
                    </form>

                    <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse dark:text-zinc-400">
                        <span>{{ __('Already have an account?') }}</span>
                        <button type="button" class="cursor-pointer font-medium text-brand-green hover:underline" @click="$wire.openLoginModal()">
                            {{ __('Log in') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
