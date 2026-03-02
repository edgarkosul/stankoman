<?php

namespace App\Livewire\Auth;

use App\Concerns\ResolvesAuthRedirectTarget;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class VerifyEmailInline extends Component
{
    use ResolvesAuthRedirectTarget;

    protected $listeners = [
        'showVerifyEmailModal' => 'open',
        'hideVerifyEmailModal' => 'close',
    ];

    public bool $open = false;

    public bool $linkSent = false;

    public function open(): void
    {
        $this->resetErrorBag();
        $this->linkSent = false;

        $user = Auth::user();

        if (! $user instanceof MustVerifyEmail) {
            $this->dispatch('auth:redirect', url: $this->resolveRedirectTarget());

            return;
        }

        if ($user->hasVerifiedEmail()) {
            $this->dispatch('auth:redirect', url: $this->resolveRedirectTarget());

            return;
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->linkSent = false;
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if (! $user instanceof MustVerifyEmail) {
            $this->close();

            return;
        }

        if ($user->hasVerifiedEmail()) {
            $this->continueToApp();

            return;
        }

        $user->sendEmailVerificationNotification();

        $this->resetErrorBag();
        $this->linkSent = true;
    }

    public function continueToApp(): void
    {
        $this->resetErrorBag();

        $user = Auth::user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $this->addError('verification', __('Подтвердите адрес электронной почты перед продолжением.'));

            return;
        }

        $this->close();
        $this->skipRender();

        $this->dispatch('auth:redirect', url: $this->resolveRedirectTarget());
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        $this->close();
        $this->skipRender();

        $this->dispatch('auth:redirect', url: route('home', absolute: false));
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email-inline');
    }
}
