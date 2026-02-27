<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class ForgotPasswordInline extends Component
{
    protected $listeners = [
        'showForgotPasswordModal' => 'open',
        'hideForgotPasswordModal' => 'close',
    ];

    public string $email = '';

    public bool $open = false;

    public function open(): void
    {
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset(['email']);
        $this->open = false;
    }

    public function openLoginModal(): void
    {
        $this->close();
        $this->dispatch('showLoginModal');
    }

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        Session::flash('status', __('A reset link will be sent if the account exists.'));

        $this->close();
        $this->skipRender();

        $this->dispatch('showLoginModal');
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password-inline');
    }
}
