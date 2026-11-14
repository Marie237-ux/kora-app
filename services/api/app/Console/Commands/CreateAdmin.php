<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'kora:create-admin';

    protected $description = 'Créer le premier propriétaire, sans mot de passe conservé dans les sources';

    public function handle(): int
    {
        if (User::whereJsonContains('roles', 'owner')->exists()) {
            $this->error('Un propriétaire existe déjà. Utiliser une procédure de délégation auditée.');

            return self::FAILURE;
        }
        $data = [
            'name' => $this->ask('Nom'),
            'email' => strtolower($this->ask('Email')),
            'password' => $this->secret('Mot de passe (12 caractères minimum, lettres et chiffres)'),
        ];
        $validation = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);
        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }
        $user = new User($data);
        $user->roles = ['owner'];
        $user->save();
        $this->info('Propriétaire créé. Configurer le second facteur puis se reconnecter avant l’accès à la gestion.');

        return self::SUCCESS;
    }
}
