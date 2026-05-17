<?php
declare(strict_types=1);

namespace App\Validators;

/**
 * Validator per la creazione/aggiornamento di un piano turni.
 *
 * `stato` non è qui: viene gestito da azioni dedicate (publish/archive) sul controller,
 * non da un form libero. In create lo stato è sempre 'bozza' (default DB).
 */
final class PianoTurnoValidator extends BaseValidator
{
    private const ANNO_MIN = 2020;
    private const ANNO_MAX = 2100;

    /**
     * @param list<int> $settingIdValidi ID di setting esistenti.
     */
    public function __construct(private readonly array $settingIdValidi)
    {
    }

    public function validate(array $input): array
    {
        $errors = [];
        $data = [];

        $annoRaw = $input['anno'] ?? '';
        if ($err = Rules::required($annoRaw, 'Anno')) {
            $errors['anno'][] = $err;
        } elseif ($err = Rules::integer($annoRaw, 'Anno')) {
            $errors['anno'][] = $err;
        } else {
            $anno = (int) $annoRaw;
            if ($err = Rules::intRange($anno, self::ANNO_MIN, self::ANNO_MAX, 'Anno')) {
                $errors['anno'][] = $err;
            } else {
                $data['anno'] = $anno;
            }
        }

        $meseRaw = $input['mese'] ?? '';
        if ($err = Rules::required($meseRaw, 'Mese')) {
            $errors['mese'][] = $err;
        } elseif ($err = Rules::integer($meseRaw, 'Mese')) {
            $errors['mese'][] = $err;
        } else {
            $mese = (int) $meseRaw;
            if ($err = Rules::intRange($mese, 1, 12, 'Mese')) {
                $errors['mese'][] = $err;
            } else {
                $data['mese'] = $mese;
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

        $nome = trim((string) ($input['nome'] ?? ''));
        if ($nome === '') {
            $data['nome'] = null;
        } elseif ($err = Rules::maxLen($nome, 100, 'Nome piano')) {
            $errors['nome'][] = $err;
        } else {
            $data['nome'] = $nome;
        }

        return $this->result($data, $errors);
    }
}
