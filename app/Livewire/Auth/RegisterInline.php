<?php

namespace App\Livewire\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Concerns\ResolvesAuthRedirectTarget;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class RegisterInline extends Component
{
    use ResolvesAuthRedirectTarget;

    protected $listeners = [
        'showRegisterModal' => 'open',
        'hideRegisterModal' => 'close',
    ];

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $accept_terms = false;

    public bool $open = false;

    public function open(): void
    {
        $this->resetValidation();
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset(['name', 'email', 'password', 'password_confirmation', 'accept_terms']);
        $this->open = false;
    }

    public function openLoginModal(): void
    {
        $this->close();
        $this->dispatch('showLoginModal');
    }

    public function register(CreateNewUser $createNewUser): void
    {
        $user = $createNewUser->create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
            'accept_terms' => $this->accept_terms,
        ]);

        event(new Registered($user));

        Auth::login($user);

        $this->close();
        $this->skipRender();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $this->dispatch('auth:redirect', url: route('verification.notice', absolute: false));

            return;
        }

        $this->dispatch('auth:redirect', url: $this->resolveRedirectTarget());
    }

    public function render(): View
    {
        return view('livewire.auth.register-inline');
    }
}
