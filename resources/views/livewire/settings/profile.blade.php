@php
    $inputClasses = 'h-11 w-full border border-zinc-300 bg-white px-3 text-sm text-zinc-900 placeholder:text-zinc-400 outline-none transition focus:border-brand-green focus:ring-2 focus:ring-brand-green/30 disabled:cursor-not-allowed disabled:opacity-70 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500';
    $linkBaseClasses = 'block border-b border-zinc-200 px-4 py-3 text-sm font-medium transition last:border-b-0 dark:border-zinc-700';
@endphp

<section class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
    <header class="mb-8 space-y-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">
        <h1 class="text-3xl font-semibold text-zinc-900 dark:text-zinc-100">Настройки</h1>
        <p class="text-brand-gray">Управляйте профилем и настройками учётной записи</p>
    </header>

    <div class="grid gap-8 md:grid-cols-[220px_minmax(0,1fr)] md:items-start" x-data>
        <nav class="bg-white dark:border-zinc-700 dark:bg-zinc-900/40" aria-label="Настройки">
            <a
                href="{{ route('profile.edit') }}"
                wire:navigate
                class="{{ $linkBaseClasses }} {{ request()->routeIs('profile.edit') ? 'bg-zinc-600 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-700 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}"
            >
                Профиль
            </a>
            <a
                href="{{ route('user-password.edit') }}"
                wire:navigate
                class="{{ $linkBaseClasses }} {{ request()->routeIs('user-password.edit') ? 'bg-zinc-600 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-700 hover:bg-zinc-200 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}"
            >
                Пароль
            </a>
        </nav>

        <div class="space-y-6 bg-white px-6 dark:border-zinc-700 dark:bg-zinc-900/40 xs:min-w-sm sm:min-w-lg">
            <div class="space-y-1 border-b border-zinc-200 pb-4 dark:border-zinc-700">
                <h2 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Профиль</h2>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Обновите имя и адрес электронной почты</p>
            </div>

            <form wire:submit="updateProfileInformation" class="space-y-5" data-test="settings-profile-form">
                <div>
                    <label for="settings-profile-name" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">Имя</label>
                    <input
                        id="settings-profile-name"
                        wire:model="name"
                        type="text"
                        required
                        autofocus
                        autocomplete="name"
                        class="{{ $inputClasses }}"
                    />
                    @error('name')
                        <p class="mt-1 text-sm text-brand-red">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-3">
                    <div>
                        <label for="settings-profile-email" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">Электронная почта</label>
                        <input
                            id="settings-profile-email"
                            wire:model="email"
                            type="email"
                            required
                            autocomplete="email"
                            class="{{ $inputClasses }}"
                        />
                        @error('email')
                            <p class="mt-1 text-sm text-brand-red">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($this->hasUnverifiedEmail)
                        <div class="space-y-2 border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
                            <p>Ваш адрес электронной почты не подтверждён.</p>
                            <button
                                type="button"
                                wire:click="resendVerificationNotification"
                                class="cursor-pointer text-left font-medium underline-offset-2 hover:underline"
                            >
                                Нажмите здесь, чтобы отправить письмо для подтверждения повторно.
                            </button>

                            @if (session('status') === 'verification-link-sent')
                                <p class="font-medium text-green-700 dark:text-green-400">
                                    Новая ссылка для подтверждения отправлена на вашу почту.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <button
                        type="submit"
                        class="inline-flex h-11 items-center justify-center border border-brand-green bg-brand-green px-5 text-sm font-semibold text-white transition hover:bg-brand-green/90 disabled:cursor-not-allowed disabled:opacity-70"
                        wire:loading.attr="disabled"
                        wire:target="updateProfileInformation"
                    >
                        <span wire:loading.remove wire:target="updateProfileInformation">Сохранить</span>
                        <span wire:loading wire:target="updateProfileInformation">Сохранение...</span>
                    </button>

                    <p
                        x-data="{ shown: false, timeout: null }"
                        x-init="@this.on('profile-updated', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2000); })"
                        x-show="shown"
                        x-transition.opacity.duration.500ms
                        style="display: none;"
                        class="text-sm font-medium text-green-600 dark:text-green-400"
                    >
                        Сохранено.
                    </p>
                </div>
            </form>
        </div>
    </div>

    @if ($this->showDeleteUser)
        <div class="mt-8">
            <livewire:settings.delete-user-form />
        </div>
    @endif
</section>
