<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator per una riga di `operatori_vincoli`.
 *
 * Sessione 5-bis (2026-05-23). Pattern sulla scia di AssenzaValidator.
 *
 * Valida solo formato/sintassi. L'esistenza di id_operatore e' verificata dal
 * controller dopo aver letto il record correlato (pattern consolidato).
 *
 * Codici tipo vincolo riconosciuti (set chiuso lato applicativo, vedi memoria
 * `project-vincoli-operatori`): `no_notti`, `no_weekend`, `solo_mattine`. Il
 * campo `tipo_vincolo` nel DB resta VARCHAR(50) per future estensioni
 * (es. `no_festivi`): si estende aggiungendo qui un'entry, senza migration.
 */
final class VincoloValidator extends BaseValidator
{
    /**
     * Codici tipo vincolo ammessi. Mappa: codice => etichetta breve.
     * La mappa "codice => frase parlata" per il form turno e' inline in
     * `views/turni/form.twig` per evitare di doverla esporre come variabile.
     *
     * @var array<string,string>
     */
    public const TIPI = [
        'no_notti'     => 'Niente notti',
        'no_weekend'   => 'Niente weekend',
        'solo_mattine' => 'Solo mattine',
    ];

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

        $tipoRaw = (string) ($input['tipo_vincolo'] ?? '');
        if ($err = Rules::required($tipoRaw, 'Tipo di vincolo')) {
            $errors['tipo_vincolo'][] = $err;
        } elseif ($err = Rules::inSet($tipoRaw, array_keys(self::TIPI), 'Tipo di vincolo')) {
            $errors['tipo_vincolo'][] = $err;
        } else {
            $data['tipo_vincolo'] = $tipoRaw;
        }

        // Checkbox: assente nel POST -> false; presente -> true.
        // Coerente con il pattern di OperatoreValidator (`operatori.attivo`).
        $data['attivo'] = Rules::toBool($input['attivo'] ?? false) ? 1 : 0;

        // Date opzionali (NULL = "sempre"). Se entrambe presenti, fine >= inizio.
        $inizioRaw = trim((string) ($input['data_inizio'] ?? ''));
        $inizioOk = false;
        if ($inizioRaw === '') {
            $data['data_inizio'] = null;
        } elseif ($err = Rules::date($inizioRaw, 'Data inizio')) {
            $errors['data_inizio'][] = $err;
        } else {
            $data['data_inizio'] = $inizioRaw;
            $inizioOk = true;
        }

        $fineRaw = trim((string) ($input['data_fine'] ?? ''));
        $fineOk = false;
        if ($fineRaw === '') {
            $data['data_fine'] = null;
        } elseif ($err = Rules::date($fineRaw, 'Data fine')) {
            $errors['data_fine'][] = $err;
        } else {
            $data['data_fine'] = $fineRaw;
            $fineOk = true;
        }

        // Confronto lessicografico su Y-m-d (== cronologico), come 4-quinquies/4-sexies/5.
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
