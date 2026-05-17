<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Accesso alla configurazione applicativa.
 *
 * I valori provengono da:
 * 1) variabili d'ambiente (.env) per i segreti
 * 2) file PHP in config/ per la struttura
 *
 * Uso: Config::get('app.debug', false)
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        // I file di config sono caricati on-demand per chiave radice
        [$root, $rest] = self::splitKey($key);

        if (!array_key_exists($root, self::$cache)) {
            $path = APP_ROOT . '/config/' . $root . '.php';
            self::$cache[$root] = file_exists($path) ? require $path : null;
        }

        $value = self::$cache[$root];
        foreach ($rest as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value ?? $default;
    }

    /**
     * Helper per leggere variabili d'ambiente con default e cast automatici
     * dei valori "true"/"false"/"null".
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /** @return array{0:string, 1:list<string>} */
    private static function splitKey(string $key): array
    {
        $parts = explode('.', $key);
        $root = array_shift($parts);
        return [$root, $parts];
    }
}
