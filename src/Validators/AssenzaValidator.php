<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator per una riga di `assenze` (ferie/permesso/malattia/maternita').
 *
 * Valida solo formato/sintassi. L'esistenza degli ID (operatore, tipo turno)
 * e' verificata dal controller dopo aver letto i record correlati — pattern
 * consolidato (vedi TurnoValidator, OperatoreValidator).
 *
 * Regola di coerenza interna: data_fine >= data_inizio.
 */
final class AssenzaValidator extends BaseValidator
{
    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $opRaw = $input['id_operatore'] ?? '';
        if ($err = Rules::required($opRaw, 'Operatore')) {
            $errors['id_operatore'][] = $err;
        } elseif ($err = Rules::integer($opRaw, 'Operatore')) {
            $errors['id_operatore'][] = $err;
        } else {
            $data['id_operatore'] = (int) $opRaw;
        }

        $tipoRaw = $input['id_tipo_turno'] ?? '';
        if ($err = Rules::required($tipoRaw, 'Tipo di assenza')) {
            $errors['id_tipo_turno'][] = $err;
        } elseif ($err = Rules::integer($tipoRaw, 'Tipo di assenza')) {
            $errors['id_tipo_turno'][] = $err;
        } else {
            $data['id_tipo_turno'] = (int) $tipoRaw;
        }

        $inizioRaw = trim((string) ($input['data_inizio'] ?? ''));
        $inizioOk = false;
        if ($err = Rules::required($inizioRaw, 'Data inizio')) {
            $errors['data_inizio'][] = $err;
        } elseif ($err = Rules::date($inizioRaw, 'Data inizio')) {
            $errors['data_inizio'][] = $err;
        } else {
            $data['data_inizio'] = $inizioRaw;
            $inizioOk = true;
        }

        $fineRaw = trim((string) ($input['data_fine'] ?? ''));
        $fineOk = false;
        if ($err = Rules::required($fineRaw, 'Data fine')) {
            $errors['data_fine'][] = $err;
        } elseif ($err = Rules::date($fineRaw, 'Data fine')) {
            $errors['data_fine'][] = $err;
        } else {
            $data['data_fine'] = $fineRaw;
            $fineOk = true;
        }

        // Coerenza inizio/fine: confronto lessicografico su Y-m-d (== cronologico).
        if ($inizioOk && $fineOk && $data['data_fine'] < $data['data_inizio']) {
            $errors['data_fine'][] = 'La data di fine non può essere precedente alla data di inizio.';
        }

        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            $data['note'] = null;
        } elseif ($err = Rules::maxLen($note, 1000, 'Note')) {
            $errors['note'][] = $err;
        } else {
            $data['note'] = $note;
        }

        return $this->result($data, $errors);
    }
}
