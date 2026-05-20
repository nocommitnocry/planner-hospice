<?php
declare(strict_types=1);

namespace App\Validators;

final class TipoTurnoValidator extends BaseValidator
{
    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $codice = trim((string) ($input['codice'] ?? ''));
        if ($err = Rules::required($codice, 'Codice')) {
            $errors['codice'][] = $err;
        } elseif ($err = Rules::maxLen($codice, 10, 'Codice')) {
            $errors['codice'][] = $err;
        } else {
            $data['codice'] = mb_strtoupper($codice);
        }

        $descrizione = trim((string) ($input['descrizione'] ?? ''));
        if ($err = Rules::required($descrizione, 'Descrizione')) {
            $errors['descrizione'][] = $err;
        } elseif ($err = Rules::maxLen($descrizione, 100, 'Descrizione')) {
            $errors['descrizione'][] = $err;
        } else {
            $data['descrizione'] = $descrizione;
        }

        $oraInizio = trim((string) ($input['ora_inizio'] ?? ''));
        if ($oraInizio === '') {
            $data['ora_inizio'] = null;
        } elseif ($err = Rules::time($oraInizio, 'Ora inizio')) {
            $errors['ora_inizio'][] = $err;
        } else {
            $data['ora_inizio'] = strlen($oraInizio) === 5 ? $oraInizio . ':00' : $oraInizio;
        }

        $oraFine = trim((string) ($input['ora_fine'] ?? ''));
        if ($oraFine === '') {
            $data['ora_fine'] = null;
        } elseif ($err = Rules::time($oraFine, 'Ora fine')) {
            $errors['ora_fine'][] = $err;
        } else {
            $data['ora_fine'] = strlen($oraFine) === 5 ? $oraFine . ':00' : $oraFine;
        }

        $colore = trim((string) ($input['colore'] ?? ''));
        if ($colore === '') {
            $colore = '#FFFFFF';
        }
        if ($err = Rules::hexColor($colore, 'Colore')) {
            $errors['colore'][] = $err;
        } else {
            $data['colore'] = strtoupper($colore);
        }

        $oreRaw = $input['ore_conteggiate'] ?? '0';
        $oreRaw = str_replace(',', '.', (string) $oreRaw);
        if ($err = Rules::decimal($oreRaw, 'Ore conteggiate')) {
            $errors['ore_conteggiate'][] = $err;
        } else {
            $ore = (float) $oreRaw;
            if ($err = Rules::decimalRange($ore, 0.0, 99.99, 'Ore conteggiate')) {
                $errors['ore_conteggiate'][] = $err;
            } else {
                $data['ore_conteggiate'] = number_format($ore, 2, '.', '');
            }
        }

        $prioritaRaw = $input['priorita'] ?? '0';
        if ($err = Rules::integer($prioritaRaw, 'Priorità')) {
            $errors['priorita'][] = $err;
        } else {
            $priorita = (int) $prioritaRaw;
            if ($err = Rules::intRange($priorita, 0, 999, 'Priorità')) {
                $errors['priorita'][] = $err;
            } else {
                $data['priorita'] = $priorita;
            }
        }

        foreach (['is_riposo', 'is_ferie', 'is_permesso', 'is_malattia', 'is_formazione', 'esclude_pianificazione'] as $flag) {
            $data[$flag] = Rules::toBool($input[$flag] ?? false) ? 1 : 0;
        }

        return $this->result($data, $errors);
    }
}
