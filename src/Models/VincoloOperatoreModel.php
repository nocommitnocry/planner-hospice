<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Vincoli di pianificazione di un operatore (no_notti, no_weekend, solo_mattine).
 *
 * Sessione 5-bis (2026-05-23). Tabella esistente dallo schema iniziale, prima
 * popolata a mano: ora ha CRUD via VincoliController sulla scia del pattern
 * AssenzeController.
 *
 * I vincoli NON sono bloccanti runtime: sono input del generatore (sessione 6)
 * e warning informativo nel form turno. Vedi memoria `project-vincoli-operatori`.
 */
final class VincoloOperatoreModel extends BaseModel
{
    protected string $table = 'operatori_vincoli';

    protected array $fillable = [
        'id_operatore',
        'tipo_vincolo',
        'attivo',
        'data_inizio',
        'data_fine',
        'note',
        'creato_da',
    ];

    /**
     * Lista vincoli joinati con operatore (cognome/nome/setting) e con l'utente
     * che li ha creati. Ordinati per cognome/nome operatore e poi tipo vincolo.
     *
     * Filtri opzionali:
     * - $idSetting: setting "di casa" dell'operatore
     * - $idOperatore: vincoli di un singolo operatore
     *
     * @return list<array<string,mixed>>
     */
    public function listJoined(?int $idSetting = null, ?int $idOperatore = null): array
    {
        $sql = "SELECT v.*,
                       o.cognome AS operatore_cognome,
                       o.nome    AS operatore_nome,
                       o.id_setting AS operatore_id_setting,
                       s.codice  AS setting_codice,
                       s.nome    AS setting_nome,
                       u.username AS creato_da_username
                FROM operatori_vincoli v
                JOIN operatori o   ON o.id = v.id_operatore
                JOIN setting s     ON s.id = o.id_setting
                LEFT JOIN utenti u ON u.id = v.creato_da";
        $where = [];
        $params = [];
        if ($idSetting !== null) {
            $where[] = 'o.id_setting = :id_setting';
            $params['id_setting'] = $idSetting;
        }
        if ($idOperatore !== null) {
            $where[] = 'v.id_operatore = :id_operatore';
            $params['id_operatore'] = $idOperatore;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.cognome ASC, o.nome ASC, v.tipo_vincolo ASC';
        return $this->db->query($sql, $params);
    }
}
