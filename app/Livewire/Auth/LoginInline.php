<?php

namespace App\Livewire\Auth;

use App\Concerns\ResolvesAuthRedirectTarget;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginInline extends Component
{
    use ResolvesAuthRedirectTarget;

    protected $listeners = [
        'showLoginModal' => 'open',
        'hideLoginModal' => 'close',
    ];

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public bool $open = false;

    public function open(): void
    {
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset(['email', 'password', 'remember']);
        $this->open = false;
    }

    public function openRegisterModal(): void
    {
        $this->close();
        $this->dispatch('showRegisterModal');
    }

    public function openForgotPasswordModal(): void
    {
        $this->close();
        $this->dispatch('showForgotPasswordModal');
    }

    /**
     * Handle an inline authentication request.
     */
    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt([
            'email' => $this->email,
            'password' => $this->password,
        ], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $user = Auth::user();

        $this->close();
        $this->skipRender();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $this->dispatch('auth:logged-in');
            $this->dispatch('showVerifyEmailModal');

            return;
        }

        $this->dispatch('auth:redirect', url: $this->resolveRedirectTarget());
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.login-inline');
    }
}
