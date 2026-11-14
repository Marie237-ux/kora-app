<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function update($user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ])->validate();
        $user->forceFill(['password' => $input['password'], 'remember_token' => Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
    }
}
