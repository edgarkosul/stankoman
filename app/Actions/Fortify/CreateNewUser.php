<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $profileRules = $this->profileRules();
        $profileRules['email'][] = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && User::isFilamentAdminEmail($value)) {
                $fail('Этот email используется для доступа в админку и не может использоваться для аккаунта покупателя.');
            }
        };

        Validator::make(
            $input,
            [
                ...$profileRules,
                'password' => $this->passwordRules(),
                'accept_terms' => ['accepted'],
            ],
            [
                'email.unique' => 'Пользователь с таким email уже существует.',
                'accept_terms.accepted' => 'Необходимо согласиться с пользовательским соглашением и политикой обработки персональных данных.',
            ],
            [
                'accept_terms' => 'пользовательское соглашение и политика обработки персональных данных',
            ],
        )->validate();

        return User::create([
            'name' => $input['name'],
            'email' => Str::lower(trim((string) $input['email'])),
            'password' => $input['password'],
        ]);
    }
}
