<?php

namespace App\Livewire\Header;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class UserMenu extends Component
{
    protected $listeners = ['auth:logged-in' => '$refresh'];

    public function openLoginModal(): void
    {
        $this->dispatch('showLoginModal');
    }

    public function openVerifyEmailModal(): void
    {
        $this->dispatch('showVerifyEmailModal');
    }

    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        $this->dispatch('auth:redirect', url: route('home', absolute: false));
    }

    public function render(): View
    {
        return view('livewire.header.user-menu');
    }
}
