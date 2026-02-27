<div>
    @if ($open)
        <flux:modal wire:model.self="open" wire:close="close" wire:cancel="close" class="md:w-[30rem]">
            <div class="flex flex-col gap-6">
                <div class="text-center">
                    <flux:heading size="lg">{{ __('Verify your email address') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('We sent a verification link to :email. Please open it before continuing.', ['email' => auth()->user()?->email ?? '']) }}
                    </flux:text>
                </div>

                @if ($linkSent)
                    <flux:text class="text-center font-medium !text-green-600 !dark:text-green-400">
                        {{ __('A new verification link has been sent to your email address.') }}
                    </flux:text>
                @endif

                @error('verification')
                    <flux:text class="text-center font-medium !text-red-600 !dark:text-red-400">
                        {{ $message }}
                    </flux:text>
                @enderror

                <div class="flex flex-col gap-3">
                    <flux:button
                        variant="primary"
                        class="w-full"
                        wire:click="resendVerificationNotification"
                        data-test="verify-email-inline-resend-button"
                    >
                        {{ __('Resend verification email') }}
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        class="w-full"
                        wire:click="continueToApp"
                        data-test="verify-email-inline-continue-button"
                    >
                        {{ __('I have verified my email') }}
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        class="w-full"
                        wire:click="logout"
                        data-test="verify-email-inline-logout-button"
                    >
                        {{ __('Log out') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
