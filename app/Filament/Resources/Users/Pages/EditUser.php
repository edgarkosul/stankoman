<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('sendPasswordResetLink')
                ->label('Ссылка на смену пароля')
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->visible(fn (User $record): bool => filled($record->email))
                ->action(function (User $record): void {
                    $status = Password::broker()->sendResetLink([
                        'email' => $record->email,
                    ]);

                    if ($status === Password::RESET_LINK_SENT) {
                        Notification::make()
                            ->success()
                            ->title('Ссылка отправлена')
                            ->body('Письмо со ссылкой на смену пароля отправлено клиенту на email.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('Не удалось отправить ссылку')
                        ->body(__($status))
                        ->send();
                }),
            Action::make('sendEmailVerification')
                ->label('Отправить письмо для подтверждения email')
                ->icon('heroicon-o-envelope')
                ->requiresConfirmation()
                ->visible(
                    fn (User $record): bool => method_exists($record, 'hasVerifiedEmail')
                        ? (! $record->hasVerifiedEmail())
                        : is_null($record->email_verified_at)
                )
                ->action(function (User $record): void {
                    if (method_exists($record, 'sendEmailVerificationNotification')) {
                        $record->sendEmailVerificationNotification();

                        Notification::make()
                            ->success()
                            ->title('Письмо отправлено')
                            ->body('Клиенту отправлено письмо для подтверждения email.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->warning()
                        ->title('Не настроена верификация email')
                        ->body('Модель User не реализует MustVerifyEmail или не имеет метода sendEmailVerificationNotification().')
                        ->send();
                }),
        ];
    }
}
