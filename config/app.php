<?php
declare(strict_types=1);

use App\Helpers\Config;

/**
 * Configurazione applicativa generale.
 *
 * I segreti (chiavi, password DB) NON stanno qui: vengono da .env.
 */
return [
    'name'      => 'Planner Hospice',
    'env'       => Config::env('APP_ENV', 'production'),
    'debug'     => filter_var(Config::env('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN),
    'url'       => Config::env('APP_URL', 'http://localhost'),
    'timezone'  => Config::env('APP_TIMEZONE', 'Europe/Rome'),
    'locale'    => Config::env('APP_LOCALE', 'it_IT'),
    'key'       => Config::env('APP_KEY', ''),

    'session' => [
        'lifetime'    => (int) Config::env('SESSION_LIFETIME', 7200),
        'secure'      => filter_var(Config::env('SESSION_SECURE', '0'), FILTER_VALIDATE_BOOLEAN),
        'name'        => Config::env('SESSION_NAME', 'planner_hospice_session'),
        'cookie_path' => '/',
        'samesite'    => 'Lax',
    ],

    'auth' => [
        'min_password_length' => (int) Config::env('AUTH_MIN_PASSWORD_LENGTH', 10),
        'max_login_attempts'  => (int) Config::env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'     => (int) Config::env('AUTH_LOCKOUT_MINUTES', 15),
    ],

    'log' => [
        'level' => Config::env('LOG_LEVEL', 'info'),
        'path'  => APP_ROOT . '/storage/logs/app.log',
    ],
];
