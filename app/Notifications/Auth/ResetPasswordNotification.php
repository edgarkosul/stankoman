<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $passwordBroker = config('auth.defaults.passwords');

        return (new MailMessage)
            ->subject('Сброс пароля')
            ->markdown('mail.auth.reset-password', [
                'user' => $notifiable,
                'resetUrl' => $this->resetUrl($notifiable),
                'expiresInMinutes' => config("auth.passwords.{$passwordBroker}.expire"),
                'shopName' => config('settings.general.shop_name', config('app.name')),
            ]);
    }
}
