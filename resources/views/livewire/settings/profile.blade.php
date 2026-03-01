<section class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
    <div class="mb-6 w-full border-b border-zinc-200 pb-3 space-y-2">
        <h1 class="text-3xl font-semibold">Настройки</h1>
        <div class="text-brand-gray ">Управляйте профилем и настройками учётной записи </div>
    </div>

    <div class="sr-only">Настройки</div>

    <x-settings.layout :heading="'Профиль'" :subheading="'Обновите свое имя и адрес электронной почты'">
        <form wire:submit="updateProfileInformation" class="my-6 pace-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus
                autocomplete="name"/>

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer"
                                wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full rounded-none">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3 rounded-none" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
