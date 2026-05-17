<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator base.
 *
 * Convenzione: ogni Validator concreto implementa `validate(array $input): array`
 * e ritorna:
 *   [
 *     'ok'     => bool,
 *     'data'   => array<string,mixed>   // valori sanitizzati pronti per il Model
 *     'errors' => array<string,list<string>>  // chiave = nome campo, valore = messaggi
 *   ]
 *
 * Il chiamante (controller) decide cosa fare: in caso di errori tipicamente
 * rimette in flash gli errori e l'input vecchio e fa redirect al form.
 *
 * Differenza rispetto al vecchio progetto: la validazione NON avveniva o
 * avveniva nei controller, mischiata con la logica DB. Centralizzandola in
 * classi dedicate la rendiamo testabile e riusabile.
 */
abstract class BaseValidator
{
    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,data:array<string,mixed>,errors:array<string,list<string>>}
     */
    abstract public function validate(array $input): array;

    /**
     * Compone la struttura di ritorno.
     *
     * @param array<string,mixed> $data
     * @param array<string,list<string>> $errors
     * @return array{ok:bool,data:array<string,mixed>,errors:array<string,list<string>>}
     */
    protected function result(array $data, array $errors): array
    {
        return [
            'ok'     => $errors === [],
            'data'   => $data,
            'errors' => $errors,
        ];
    }
}
