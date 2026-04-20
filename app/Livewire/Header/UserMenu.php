<?php

namespace App\Livewire\Header;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class UserMenu extends Component
{
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

    public function isFilamentAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isFilamentAdmin();
    }

    public function filamentAdminUrl(): ?string
    {
        if (! $this->isFilamentAdmin()) {
            return null;
        }

        return Filament::getPanel('admin', isStrict: false)?->getUrl();
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
