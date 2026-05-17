<?php
declare(strict_types=1);

/**
 * Bootstrap dell'applicazione.
 *
 * - carica l'autoloader Composer
 * - inizializza le variabili d'ambiente da .env
 * - imposta error reporting, timezone, locale
 * - definisce le costanti di percorso
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_START_TIME', microtime(true));

require APP_ROOT . '/vendor/autoload.php';

// Carica .env (silenzioso se assente: utile per ambienti dove le env arrivano dal sistema)
if (file_exists(APP_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(APP_ROOT);
    $dotenv->safeLoad();
}

// Timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

// Locale per intl
$locale = $_ENV['APP_LOCALE'] ?? 'it_IT';
if (class_exists('Locale')) {
    Locale::setDefault($locale);
}

// Error reporting
$debug = filter_var($_ENV['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
