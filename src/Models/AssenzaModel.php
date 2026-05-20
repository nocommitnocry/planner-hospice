<?php
declare(strict_types=1);

namespace App\Models;

final class AssenzaModel extends BaseModel
{
    protected string $table = 'assenze';

    protected array $fillable = [
        'id_operatore',
        'id_tipo_turno',
        'data_inizio',
        'data_fine',
        'note',
        'creato_da',
    ];

    /**
     * Lista assenze joinate con operatore (cognome/nome/setting) e tipo turno
     * (codice/descrizione/colore/esclude_pianificazione). Ordinate per
     * data_inizio DESC (le più recenti in alto).
     *
     * Filtri opzionali:
     * - $idSetting: setting "di casa" dell'operatore
     * - $idOperatore: tutte le assenze di un singolo operatore
     *
     * @return list<array<string,mixed>>
     */
    public function listJoined(?int $idSetting = null, ?int $idOperatore = null): array
    {
        $sql = "SELECT a.*,
                       o.cognome AS operatore_cognome,
                       o.nome    AS operatore_nome,
                       o.id_setting AS operatore_id_setting,
                       s.codice  AS setting_codice,
                       s.nome    AS setting_nome,
                       t.codice  AS tipo_codice,
                       t.descrizione AS tipo_descrizione,
                       t.colore  AS tipo_colore,
                       t.esclude_pianificazione AS tipo_esclude_pianificazione,
                       u.username AS creato_da_username
                FROM assenze a
                JOIN operatori o   ON o.id = a.id_operatore
                JOIN setting s     ON s.id = o.id_setting
                JOIN tipi_turno t  ON t.id = a.id_tipo_turno
                LEFT JOIN utenti u ON u.id = a.creato_da";
        $where = [];
        $params = [];
        if ($idSetting !== null) {
            $where[] = 'o.id_setting = :id_setting';
            $params['id_setting'] = $idSetting;
        }
        if ($idOperatore !== null) {
            $where[] = 'a.id_operatore = :id_operatore';
            $params['id_operatore'] = $idOperatore;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.data_inizio DESC, o.cognome ASC, o.nome ASC';
        return $this->db->query($sql, $params);
    }

    /**
     * Operatori che hanno almeno un'assenza di tipo `esclude_pianificazione=1`
     * che copre INTERAMENTE il mese (data_inizio <= primo_del_mese
     * AND data_fine >= ultimo_del_mese). Ritorna gli id_operatore distinti.
     *
     * Usato da `PianiTurnoController::store` per escludere le maternità dal
     * fotografa-operatori del piano nuovo (vedi 4-sexies).
     *
     * @return list<int>
     */
    public function listIdOperatoriEsclusiNelMese(int $anno, int $mese): array
    {
        $primo  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->modify('last day of this month')->format('Y-m-d');
        $rows = $this->db->query(
            "SELECT DISTINCT a.id_operatore
             FROM assenze a
             JOIN tipi_turno t ON t.id = a.id_tipo_turno
             WHERE t.esclude_pianificazione = 1
               AND a.data_inizio <= :primo
               AND a.data_fine   >= :ultimo",
            ['primo' => $primo, 'ultimo' => $ultimo],
        );
        return array_map(static fn ($r) => (int) $r['id_operatore'], $rows);
    }
}
