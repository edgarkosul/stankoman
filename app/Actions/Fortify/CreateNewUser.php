<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
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
        Validator::make(
            $input,
            [
                ...$this->profileRules(),
                'password' => $this->passwordRules(),
                'accept_terms' => ['accepted'],
            ],
            [
                'accept_terms.accepted' => 'Необходимо согласиться с пользовательским соглашением и политикой обработки персональных данных.',
            ],
            [
                'accept_terms' => 'пользовательское соглашение и политика обработки персональных данных',
            ],
        )->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
