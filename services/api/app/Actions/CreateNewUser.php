<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'locale' => ['sometimes', 'in:fr,en'],
            'theme' => ['sometimes', 'in:light,dark,system'],
        ])->validate();
        $user = new User;
        $user->name = $data['name'];
        $user->email = strtolower($data['email']);
        $user->password = $data['password'];
        $user->locale = $data['locale'] ?? app()->getLocale();
        $user->theme = $data['theme'] ?? 'system';
        $user->roles = ['client'];
        $user->save();

        return $user;
    }
}
