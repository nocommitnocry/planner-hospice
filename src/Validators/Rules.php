<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Regole di validazione riusabili.
 *
 * Ogni metodo ritorna null se il valore passa il controllo, oppure una stringa
 * con il messaggio di errore in italiano. Etichetta del campo passata come
 * parametro per messaggi parlanti ("Nome è obbligatorio" invece di "field
 * required").
 *
 * I Validator concreti compongono queste regole; questa classe non conosce
 * il dominio.
 */
final class Rules
{
    public static function required(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return "{$label} è obbligatorio.";
        }
        return null;
    }

    public static function maxLen(string $value, int $max, string $label): ?string
    {
        if (mb_strlen($value) > $max) {
            return "{$label} non può superare {$max} caratteri.";
        }
        return null;
    }

    public static function minLen(string $value, int $min, string $label): ?string
    {
        if (mb_strlen($value) < $min) {
            return "{$label} deve avere almeno {$min} caratteri.";
        }
        return null;
    }

    public static function integer(mixed $value, string $label): ?string
    {
        if (!is_numeric($value) || (int) $value != $value) {
            return "{$label} deve essere un numero intero.";
        }
        return null;
    }

    public static function intRange(int $value, int $min, int $max, string $label): ?string
    {
        if ($value < $min || $value > $max) {
            return "{$label} deve essere compreso tra {$min} e {$max}.";
        }
        return null;
    }

    public static function decimal(mixed $value, string $label): ?string
    {
        if (!is_numeric($value)) {
            return "{$label} deve essere un numero.";
        }
        return null;
    }

    public static function decimalRange(float $value, float $min, float $max, string $label): ?string
    {
        if ($value < $min || $value > $max) {
            return "{$label} deve essere compreso tra {$min} e {$max}.";
        }
        return null;
    }

    /**
     * @param list<string> $allowed
     */
    public static function inSet(mixed $value, array $allowed, string $label): ?string
    {
        if (!in_array((string) $value, $allowed, true)) {
            return "{$label} ha un valore non ammesso.";
        }
        return null;
    }

    public static function hexColor(string $value, string $label): ?string
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return "{$label} deve essere un colore in formato HEX (es. #FF0000).";
        }
        return null;
    }

    public static function time(string $value, string $label): ?string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
            return "{$label} deve essere un orario nel formato HH:MM.";
        }
        return null;
    }

    /**
     * Valida una data nel formato Y-m-d con round-trip per scartare le date
     * "morbide" tipo 2026-02-31 (DateTime le accetterebbe normalizzandole).
     */
    public static function date(string $value, string $label): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return "{$label} deve essere una data nel formato AAAA-MM-GG.";
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return "{$label} non è una data valida.";
        }
        return null;
    }

    public static function email(string $value, string $label): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return "{$label} non è un indirizzo email valido.";
        }
        return null;
    }

    /**
     * Username: lettere/numeri/underscore/punto/trattino, niente spazi.
     */
    public static function username(string $value, string $label): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            return "{$label} può contenere solo lettere, numeri, punto, trattino, underscore.";
        }
        return null;
    }

    /**
     * Normalizza un valore da checkbox HTML in booleano.
     * (Le checkbox non spuntate non arrivano nel POST: per questo la regola
     * è sull'OUTPUT, non un check di validazione.)
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower((string) $value);
        return in_array($s, ['1', 'on', 'true', 'yes', 'y'], true);
    }
}
