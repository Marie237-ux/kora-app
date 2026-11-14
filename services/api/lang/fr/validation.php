<?php

return [
    'required' => 'Le champ :attribute est requis.',
    'string' => 'Le champ :attribute doit contenir du texte.',
    'email' => 'Saisissez une adresse e-mail valide.',
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'in' => 'La valeur de :attribute n’est pas autorisée.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'boolean' => 'La valeur de :attribute doit être oui ou non.',
    'array' => 'La structure de :attribute n’est pas valide.',
    'uuid' => 'L’identifiant :attribute n’est pas valide.',
    'exists' => 'La référence :attribute est introuvable ou non publiée.',
    'regex' => 'Le format de :attribute n’est pas autorisé.',
    'date' => 'La date :attribute n’est pas valide.',
    'after' => 'La date :attribute doit être postérieure à :date.',
    'current_password' => 'Le mot de passe actuel est incorrect.',
    'distinct' => 'La référence :attribute est en double.',
    'max' => [
        'string' => ':attribute ne doit pas dépasser :max caractères.',
        'numeric' => ':attribute ne doit pas dépasser :max.',
        'array' => ':attribute ne doit pas dépasser :max éléments.',
    ],
    'min' => [
        'string' => ':attribute doit contenir au moins :min caractères.',
        'numeric' => ':attribute doit être au moins égal à :min.',
        'array' => ':attribute doit contenir au moins :min élément(s).',
    ],
    'password' => [
        'letters' => ':attribute doit contenir des lettres.',
        'numbers' => ':attribute doit contenir des chiffres.',
    ],
    'attributes' => [
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
        'name' => 'nom',
        'code' => 'code',
        'current_password' => 'mot de passe actuel',
    ],
];
