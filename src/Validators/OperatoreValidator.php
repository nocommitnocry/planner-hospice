<?php
declare(strict_types=1);

namespace App\Validators;

final class OperatoreValidator extends BaseValidator
{
    /**
     * @param list<int> $categorieIdValide ID di categorie esistenti, passati dal controller.
     * @param list<int> $settingIdValidi   ID di setting esistenti (hospice/ucp_dom).
     */
    public function __construct(
        private readonly array $categorieIdValide,
        private readonly array $settingIdValidi,
    ) {
    }

    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $nome = trim((string) ($input['nome'] ?? ''));
        if ($err = Rules::required($nome, 'Nome')) {
            $errors['nome'][] = $err;
        } elseif ($err = Rules::maxLen($nome, 100, 'Nome')) {
            $errors['nome'][] = $err;
        } else {
            $data['nome'] = $nome;
        }

        $cognome = trim((string) ($input['cognome'] ?? ''));
        if ($err = Rules::required($cognome, 'Cognome')) {
            $errors['cognome'][] = $err;
        } elseif ($err = Rules::maxLen($cognome, 100, 'Cognome')) {
            $errors['cognome'][] = $err;
        } else {
            $data['cognome'] = $cognome;
        }

        $catRaw = $input['id_categoria'] ?? '';
        if ($err = Rules::required($catRaw, 'Categoria')) {
            $errors['id_categoria'][] = $err;
        } elseif ($err = Rules::integer($catRaw, 'Categoria')) {
            $errors['id_categoria'][] = $err;
        } else {
            $cat = (int) $catRaw;
            if (!in_array($cat, $this->categorieIdValide, true)) {
                $errors['id_categoria'][] = 'Categoria non valida.';
            } else {
                $data['id_categoria'] = $cat;
            }
        }

        $settingRaw = $input['id_setting'] ?? '';
        if ($err = Rules::required($settingRaw, 'Setting')) {
            $errors['id_setting'][] = $err;
        } elseif ($err = Rules::integer($settingRaw, 'Setting')) {
            $errors['id_setting'][] = $err;
        } else {
            $set = (int) $settingRaw;
            if (!in_array($set, $this->settingIdValidi, true)) {
                $errors['id_setting'][] = 'Setting non valido.';
            } else {
                $data['id_setting'] = $set;
            }
        }

        $oreRaw = $input['ore_contrattuali_mensili'] ?? '165';
        $oreRaw = str_replace(',', '.', (string) $oreRaw);
        if ($err = Rules::decimal($oreRaw, 'Ore contrattuali mensili')) {
            $errors['ore_contrattuali_mensili'][] = $err;
        } else {
            $ore = (float) $oreRaw;
            if ($err = Rules::decimalRange($ore, 0.0, 999.99, 'Ore contrattuali mensili')) {
                $errors['ore_contrattuali_mensili'][] = $err;
            } else {
                $data['ore_contrattuali_mensili'] = number_format($ore, 2, '.', '');
            }
        }

        $assunzRaw = trim((string) ($input['data_assunzione'] ?? ''));
        if ($assunzRaw === '') {
            $data['data_assunzione'] = null;
        } elseif ($err = Rules::date($assunzRaw, 'Data assunzione')) {
            $errors['data_assunzione'][] = $err;
        } else {
            $data['data_assunzione'] = $assunzRaw;
        }

        $cessRaw = trim((string) ($input['data_cessazione'] ?? ''));
        if ($cessRaw === '') {
            $data['data_cessazione'] = null;
        } elseif ($err = Rules::date($cessRaw, 'Data cessazione')) {
            $errors['data_cessazione'][] = $err;
        } else {
            $data['data_cessazione'] = $cessRaw;
        }

        // Coerenza: cessazione >= assunzione (solo se entrambe valorizzate e valide).
        if (
            isset($data['data_assunzione'], $data['data_cessazione'])
            && $data['data_assunzione'] !== null
            && $data['data_cessazione'] !== null
            && $data['data_cessazione'] < $data['data_assunzione']
        ) {
            $errors['data_cessazione'][] = 'La data di cessazione non può essere precedente alla data di assunzione.';
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '') {
            $data['email'] = null;
        } elseif ($err = Rules::maxLen($email, 150, 'Email')) {
            $errors['email'][] = $err;
        } elseif ($err = Rules::email($email, 'Email')) {
            $errors['email'][] = $err;
        } else {
            $data['email'] = $email;
        }

        $telefono = trim((string) ($input['telefono'] ?? ''));
        if ($telefono === '') {
            $data['telefono'] = null;
        } elseif ($err = Rules::maxLen($telefono, 30, 'Telefono')) {
            $errors['telefono'][] = $err;
        } else {
            $data['telefono'] = $telefono;
        }

        $note = trim((string) ($input['note'] ?? ''));
        $data['note'] = $note === '' ? null : $note;

        $data['attivo'] = Rules::toBool($input['attivo'] ?? false) ? 1 : 0;

        return $this->result($data, $errors);
    }
}
