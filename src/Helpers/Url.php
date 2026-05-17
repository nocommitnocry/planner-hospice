<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Helper URL: costruzione di URL assoluti e versioning automatico degli asset.
 *
 * Url::asset('css/style.css') -> /css/style.css?v=<filemtime>
 */
final class Url
{
    public static function base(): string
    {
        return rtrim((string) Config::get('app.url', ''), '/');
    }

    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * URL versionato per un asset in public/.
     * In dev/local fa fall-through al filemtime; in produzione si potrebbe
     * sostituire con un manifest pre-calcolato.
     */
    public static function asset(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $absolute = APP_ROOT . '/public/' . $relative;
        $version = file_exists($absolute) ? (string) filemtime($absolute) : '';
        $query = $version !== '' ? '?v=' . $version : '';
        return '/' . $relative . $query;
    }
}
