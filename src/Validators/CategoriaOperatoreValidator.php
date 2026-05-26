<?php
declare(strict_types=1);

namespace App\Validators;

use App\Models\CategoriaOperatoreModel;

final class CategoriaOperatoreValidator extends BaseValidator
{
    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $nome = trim((string) ($input['nome'] ?? ''));
        if ($err = Rules::required($nome, 'Nome')) {
            $errors['nome'][] = $err;
        } elseif ($err = Rules::maxLen($nome, 50, 'Nome')) {
            $errors['nome'][] = $err;
        } else {
            $data['nome'] = mb_strtoupper($nome);
        }

        $descrizione = trim((string) ($input['descrizione'] ?? ''));
        if ($descrizione !== '') {
            if ($err = Rules::maxLen($descrizione, 255, 'Descrizione')) {
                $errors['descrizione'][] = $err;
            } else {
                $data['descrizione'] = $descrizione;
            }
        } else {
            $data['descrizione'] = null;
        }

        // Gruppo di pianificazione (migrazione 0011). Vuoto -> 'altro' (default DB):
        // una categoria non classificata finisce nel gruppo "Altri" della stampa.
        $gruppo = trim((string) ($input['gruppo_pianificazione'] ?? ''));
        if ($gruppo === '') {
            $data['gruppo_pianificazione'] = 'altro';
        } elseif ($err = Rules::inSet($gruppo, CategoriaOperatoreModel::GRUPPI, 'Gruppo di pianificazione')) {
            $errors['gruppo_pianificazione'][] = $err;
        } else {
            $data['gruppo_pianificazione'] = $gruppo;
        }

        $ordineRaw = $input['ordine_visualizzazione'] ?? 0;
        if ($ordineRaw === '' || $ordineRaw === null) {
            $data['ordine_visualizzazione'] = 0;
        } elseif ($err = Rules::integer($ordineRaw, 'Ordine di visualizzazione')) {
            $errors['ordine_visualizzazione'][] = $err;
        } else {
            $ordine = (int) $ordineRaw;
            if ($err = Rules::intRange($ordine, 0, 9999, 'Ordine di visualizzazione')) {
                $errors['ordine_visualizzazione'][] = $err;
            } else {
                $data['ordine_visualizzazione'] = $ordine;
            }
        }

        return $this->result($data, $errors);
    }
}
