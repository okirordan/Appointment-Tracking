<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'username',
    'email' => 'email',
    'views' => true,
    'home' => '/home',
    'prefix' => 'fortify',
    'domain' => null,
    'lowercase_usernames' => false,

    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
    ],

    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
