<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validatore per le mutazioni manuali sui saldi (sessione 4-ter).
 *
 * Tre flussi distinti, ognuno con i suoi campi obbligatori:
 *  - aggiunta operatore al piano: id_operatore, ore_dovute, saldo_progressivo_iniziale, note;
 *  - modifica saldo esistente: almeno uno tra ore_dovute / saldo_progressivo (nessun
 *    "no-op" silenzioso), nota obbligatoria;
 *
 * Le note sono SEMPRE obbligatorie per tracciabilità (le righe finiscono in
 * `saldo_modifiche`).
 */
final class SaldoValidator extends BaseValidator
{
    /**
     * Valida l'aggiunta esplicita di un operatore al piano.
     *
     * @param list<int> $operatoriIdValidi ID candidati (in servizio nel mese, non già in piano).
     */
    public function validateAggiunta(array $input, array $operatoriIdValidi): array
    {
        $errors = [];
        $data = [];

        $idOpRaw = $input['id_operatore'] ?? '';
        if ($err = Rules::required($idOpRaw, 'Operatore')) {
            $errors['id_operatore'][] = $err;
        } elseif ($err = Rules::integer($idOpRaw, 'Operatore')) {
            $errors['id_operatore'][] = $err;
        } else {
            $idOp = (int) $idOpRaw;
            if (!in_array($idOp, $operatoriIdValidi, true)) {
                $errors['id_operatore'][] = 'Operatore non valido o già presente nel piano.';
            } else {
                $data['id_operatore'] = $idOp;
            }
        }

        $data = $data + $this->parseOreDovute($input, $errors, true);
        $data = $data + $this->parseSaldoProgressivo($input, $errors, true);
        $data = $data + $this->parseNote($input, $errors);

        return $this->result($data, $errors);
    }

    /**
     * Valida la modifica di un saldo esistente. Almeno uno tra ore_dovute e
     * saldo_progressivo deve essere presente e diverso dal valore corrente
     * (quest'ultimo check lo fa il controller, perché qui non vediamo lo stato).
     */
    public function validateModifica(array $input): array
    {
        $errors = [];
        $data = [];

        $oreRaw = trim((string) ($input['ore_dovute'] ?? ''));
        if ($oreRaw !== '') {
            $data = $data + $this->parseOreDovute($input, $errors, true);
        }
        $progRaw = trim((string) ($input['saldo_progressivo'] ?? ''));
        if ($progRaw !== '') {
            $data = $data + $this->parseSaldoProgressivo($input, $errors, true);
        }

        if (!isset($data['ore_dovute']) && !isset($data['saldo_progressivo']) && $errors === []) {
            $errors['ore_dovute'][] = 'Indica almeno un valore da modificare (ore dovute o saldo progressivo).';
        }

        $data = $data + $this->parseNote($input, $errors);

        return $this->result($data, $errors);
    }

    /**
     * @param array<string,list<string>> $errors
     * @return array<string,mixed>
     */
    private function parseOreDovute(array $input, array &$errors, bool $required): array
    {
        $raw = trim((string) ($input['ore_dovute'] ?? ''));
        if ($raw === '') {
            if ($required) {
                $errors['ore_dovute'][] = 'Ore dovute è obbligatorio.';
            }
            return [];
        }
        $raw = str_replace(',', '.', $raw);
        if ($err = Rules::decimal($raw, 'Ore dovute')) {
            $errors['ore_dovute'][] = $err;
            return [];
        }
        $val = (float) $raw;
        if ($err = Rules::decimalRange($val, -999.99, 999.99, 'Ore dovute')) {
            $errors['ore_dovute'][] = $err;
            return [];
        }
        return ['ore_dovute' => number_format($val, 2, '.', '')];
    }

    /**
     * @param array<string,list<string>> $errors
     * @return array<string,mixed>
     */
    private function parseSaldoProgressivo(array $input, array &$errors, bool $required): array
    {
        $raw = trim((string) ($input['saldo_progressivo'] ?? ''));
        if ($raw === '') {
            if ($required) {
                $errors['saldo_progressivo'][] = 'Saldo progressivo è obbligatorio.';
            }
            return [];
        }
        $raw = str_replace(',', '.', $raw);
        if ($err = Rules::decimal($raw, 'Saldo progressivo')) {
            $errors['saldo_progressivo'][] = $err;
            return [];
        }
        $val = (float) $raw;
        if ($err = Rules::decimalRange($val, -9999.99, 9999.99, 'Saldo progressivo')) {
            $errors['saldo_progressivo'][] = $err;
            return [];
        }
        return ['saldo_progressivo' => number_format($val, 2, '.', '')];
    }

    /**
     * @param array<string,list<string>> $errors
     * @return array<string,mixed>
     */
    private function parseNote(array $input, array &$errors): array
    {
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            $errors['note'][] = 'La nota motivazione è obbligatoria.';
            return [];
        }
        if ($err = Rules::maxLen($note, 1000, 'Nota')) {
            $errors['note'][] = $err;
            return [];
        }
        return ['note' => $note];
    }

    /** Stub: il contract `validate(array)` non si usa per questo Validator (due flussi distinti). */
    public function validate(array $input): array
    {
        return $this->validateModifica($input);
    }
}
