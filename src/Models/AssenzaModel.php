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
     * Ritorna l'assenza dell'operatore che copre la data indicata, o null.
     * Se ce ne sono più (assenze sovrapposte, caso teorico) ne ritorna una
     * qualsiasi — per il check serve solo "c'è o no" + dati per il messaggio.
     *
     * Confronto lessicografico su stringa Y-m-d (== cronologico).
     *
     * @return array{
     *   id: int,
     *   id_operatore: int,
     *   id_tipo_turno: int,
     *   data_inizio: string,
     *   data_fine: string,
     *   note: ?string,
     *   tipo_codice: string,
     *   tipo_descrizione: string,
     * }|null
     */
    public function findAttivaPerOperatoreData(int $idOperatore, string $data): ?array
    {
        // Due placeholder distinti per la data: con ATTR_EMULATE_PREPARES=false
        // PDO non permette di riusare lo stesso named placeholder.
        $rows = $this->db->query(
            "SELECT a.id, a.id_operatore, a.id_tipo_turno,
                    a.data_inizio, a.data_fine, a.note,
                    t.codice  AS tipo_codice,
                    t.descrizione AS tipo_descrizione
             FROM assenze a
             JOIN tipi_turno t ON t.id = a.id_tipo_turno
             WHERE a.id_operatore = :id_op
               AND a.data_inizio <= :data_lo
               AND a.data_fine   >= :data_hi
             LIMIT 1",
            ['id_op' => $idOperatore, 'data_lo' => $data, 'data_hi' => $data],
        );
        if ($rows === []) {
            return null;
        }
        $r = $rows[0];
        return [
            'id'                => (int) $r['id'],
            'id_operatore'      => (int) $r['id_operatore'],
            'id_tipo_turno'     => (int) $r['id_tipo_turno'],
            'data_inizio'       => (string) $r['data_inizio'],
            'data_fine'         => (string) $r['data_fine'],
            'note'              => $r['note'] !== null ? (string) $r['note'] : null,
            'tipo_codice'       => (string) $r['tipo_codice'],
            'tipo_descrizione'  => (string) $r['tipo_descrizione'],
        ];
    }

    /**
     * Assenze degli operatori indicati che si sovrappongono al periodo
     * [dataInizio, dataFine] (anche parzialmente). Una sola query per evitare
     * N query nel rendering del calendario.
     *
     * @param list<int> $idOperatori
     * @return list<array{
     *   id_operatore: int,
     *   data_inizio: string,
     *   data_fine: string,
     *   tipo_codice: string,
     *   tipo_descrizione: string,
     * }>
     */
    public function listAttiveInPeriodo(array $idOperatori, string $dataInizio, string $dataFine): array
    {
        if ($idOperatori === []) {
            return [];
        }
        // Placeholder posizionali: con IN (?,?,?...) variabile è la via pulita
        // per evitare di costruire dinamicamente nomi unici di placeholder named
        // (regola PDO: niente named riusati). Le due date a fine lista sono
        // anch'esse posizionali.
        $inPlaceholders = implode(',', array_fill(0, count($idOperatori), '?'));
        $sql =
            "SELECT a.id_operatore, a.data_inizio, a.data_fine,
                    t.codice  AS tipo_codice,
                    t.descrizione AS tipo_descrizione
             FROM assenze a
             JOIN tipi_turno t ON t.id = a.id_tipo_turno
             WHERE a.id_operatore IN ({$inPlaceholders})
               AND a.data_inizio <= ?
               AND a.data_fine   >= ?
             ORDER BY a.id_operatore, a.data_inizio";
        $params = array_merge(
            array_map(static fn ($id) => (int) $id, $idOperatori),
            [$dataFine, $dataInizio],
        );
        return $this->db->query($sql, $params);
    }

    /**
     * Assenze di un operatore che si sovrappongono a un mese, con i flag del
     * tipo turno necessari al conteggio ore nel saldo (SchemaOreService):
     * categoria (is_ferie/permesso/malattia/formazione), esclude_pianificazione
     * e `schema_ore`. `data_inizio`/`data_fine` sono quelle PIENE del record
     * (anche fuori dal mese): servono per il restart-da-M del blocco ciclico.
     *
     * @return list<array{
     *   id:int, data_inizio:string, data_fine:string, tipo_codice:string,
     *   is_ferie:int, is_permesso:int, is_malattia:int, is_formazione:int,
     *   esclude_pianificazione:int, schema_ore:string, ore_conteggiate:string
     * }>
     */
    public function listConTipoPerOperatoreMese(int $idOperatore, int $anno, int $mese): array
    {
        $primo  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->modify('last day of this month')->format('Y-m-d');
        return $this->db->query(
            "SELECT a.id, a.data_inizio, a.data_fine,
                    t.codice AS tipo_codice,
                    t.is_ferie, t.is_permesso, t.is_malattia, t.is_formazione,
                    t.esclude_pianificazione, t.schema_ore, t.ore_conteggiate
             FROM assenze a
             JOIN tipi_turno t ON t.id = a.id_tipo_turno
             WHERE a.id_operatore = :id_op
               AND a.data_inizio <= :ultimo
               AND a.data_fine   >= :primo
             ORDER BY a.data_inizio",
            ['id_op' => $idOperatore, 'ultimo' => $ultimo, 'primo' => $primo],
        );
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
