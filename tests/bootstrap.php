<?php
declare(strict_types=1);

/**
 * Bootstrap della suite di test (PHPUnit 11).
 *
 * Definisce APP_ROOT (usato da PianoPdfService::render per i percorsi di views/
 * e della temp dir mpdf) e carica l'autoloader Composer. NON apre la connessione
 * al DB: gli unit test della sessione 8 esercitano solo logica pura (vedi
 * PianoPdfServiceTest). I test di integrazione (HTTP+DB) non sono ancora previsti.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
