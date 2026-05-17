<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator per l'assegnazione di un turno (riga in `turni`).
 *
 * Valida solo formato/sintassi dei campi. L'esistenza degli ID (operatore,
 * tipo turno) e la consistenza con il piano (data nel mese giusto, operatore
 * incluso nel piano, piano in stato modificabile) sono verificate dal
 * controller, dopo aver letto i record correlati — pattern già usato in
 * OperatoreValidator (FK categoria) e UtenteValidator.
 */
final class TurnoValidator extends BaseValidator
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
        if ($err = Rules::required($tipoRaw, 'Tipo turno')) {
            $errors['id_tipo_turno'][] = $err;
        } elseif ($err = Rules::integer($tipoRaw, 'Tipo turno')) {
            $errors['id_tipo_turno'][] = $err;
        } else {
            $data['id_tipo_turno'] = (int) $tipoRaw;
        }

        $dataRaw = trim((string) ($input['data'] ?? ''));
        if ($err = Rules::required($dataRaw, 'Data')) {
            $errors['data'][] = $err;
        } elseif (!$this->isValidDate($dataRaw)) {
            $errors['data'][] = 'Data deve essere nel formato AAAA-MM-GG.';
        } else {
            $data['data'] = $dataRaw;
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

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
