<?php
declare(strict_types=1);

namespace App\Models;

final class TurnoModel extends BaseModel
{
    protected string $table = 'turni';

    protected array $fillable = [
        'id_piano',
        'id_operatore',
        'data',
        'id_tipo_turno',
        'ore_effettive',
        'note',
    ];

    /**
     * Tutti i turni di un piano con i campi del tipo turno necessari alla UI.
     *
     * I flag `tipo_is_assenza` (derivato) servono al calendario per non
     * marcare come "conflitto" un turno che è esso stesso un'assenza coincidente
     * con un'assenza programmata (es. turno F nel periodo di un'assenza F):
     * la ridondanza non è un conflitto, va trattata come stato coerente.
     *
     * @return list<array<string,mixed>>
     */
    public function listByPiano(int $idPiano): array
    {
        $sql = "SELECT t.id, t.id_piano, t.id_operatore, t.data, t.id_tipo_turno, t.note,
                       tt.codice AS tipo_codice,
                       tt.descrizione AS tipo_descrizione,
                       tt.colore AS tipo_colore,
                       (tt.is_ferie = 1
                        OR tt.is_permesso = 1
                        OR tt.is_malattia = 1
                        OR tt.esclude_pianificazione = 1) AS tipo_is_assenza
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_piano = :id_piano
                ORDER BY t.data ASC, t.id_operatore ASC";
        return $this->db->query($sql, ['id_piano' => $idPiano]);
    }

    /**
     * Turno specifico nel piano per (operatore, data). Ritorna anche dati tipo turno
     * (servono per messaggi di errore e UI di edit).
     *
     * @return array<string,mixed>|null
     */
    public function findInPianoByOperatoreData(int $idPiano, int $idOperatore, string $data): ?array
    {
        $sql = "SELECT t.*,
                       tt.codice AS tipo_codice,
                       tt.descrizione AS tipo_descrizione,
                       (tt.is_ferie = 1
                        OR tt.is_permesso = 1
                        OR tt.is_malattia = 1
                        OR tt.esclude_pianificazione = 1) AS tipo_is_assenza
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_piano = :id_piano
                  AND t.id_operatore = :id_op
                  AND t.data = :data
                LIMIT 1";
        return $this->db->queryOne($sql, [
            'id_piano' => $idPiano,
            'id_op'    => $idOperatore,
            'data'     => $data,
        ]);
    }

    /**
     * Turni assegnati nello stesso (anno, mese) in piani DIVERSI da quello dato,
     * limitati agli operatori che appartengono al piano corrente.
     *
     * Serve all'overlay "cross-setting" nel calendario (sessione 4-quinquies):
     * per ogni cella (operatore × giorno) del mio piano voglio sapere se quello
     * stesso operatore ha un turno in un altro piano dello stesso mese, così da
     * mostrarla come occupata-altrove (non cliccabile) ed evitare di sbatterci
     * contro l'UNIQUE (operatore, data) su `turni`.
     *
     * @return list<array<string,mixed>>
     */
    public function listCrossSettingPerPiano(int $idPiano, int $anno, int $mese): array
    {
        $sql = "SELECT t.id_operatore,
                       t.data,
                       t.note,
                       t.id_tipo_turno,
                       tt.codice AS tipo_codice,
                       tt.descrizione AS tipo_descrizione,
                       tt.colore AS tipo_colore,
                       p.id   AS piano_origine_id,
                       s.codice AS setting_codice,
                       s.nome   AS setting_nome
                FROM turni t
                JOIN tipi_turno tt   ON tt.id = t.id_tipo_turno
                JOIN piano_turni p   ON p.id  = t.id_piano
                JOIN setting s       ON s.id  = p.id_setting
                JOIN piano_operatori po_corrente
                                     ON po_corrente.id_operatore = t.id_operatore
                                    AND po_corrente.id_piano     = :id_piano_corrente
                WHERE p.anno = :anno
                  AND p.mese = :mese
                  AND t.id_piano <> :id_piano_escluso
                ORDER BY t.data ASC, t.id_operatore ASC";
        return $this->db->query($sql, [
            'id_piano_corrente' => $idPiano,
            'id_piano_escluso'  => $idPiano,
            'anno'              => $anno,
            'mese'              => $mese,
        ]);
    }

    /**
     * Turni di un operatore in un dato mese, con i flag/ore del tipo turno
     * necessari al ricalcolo del saldo (lavorate / ferie / permessi / malattia /
     * formazione / riposo).
     *
     * @return list<array<string,mixed>>
     */
    public function listByOperatoreInMese(int $idOperatore, int $anno, int $mese): array
    {
        $primo = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->format('Y-m-t');

        $sql = "SELECT t.data,
                       t.ore_effettive,
                       tt.ore_conteggiate,
                       tt.ora_inizio,
                       tt.ora_fine,
                       tt.is_riposo,
                       tt.is_ferie,
                       tt.is_permesso,
                       tt.is_malattia,
                       tt.is_formazione
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_operatore = :id_op
                  AND t.data BETWEEN :primo AND :ultimo";
        return $this->db->query($sql, [
            'id_op'  => $idOperatore,
            'primo'  => $primo,
            'ultimo' => $ultimo,
        ]);
    }

    /**
     * Il turno (cross-setting) di un operatore in una data precisa, con gli orari
     * e i flag del tipo. L'UNIQUE (operatore, data) è globale → 0 o 1 riga.
     *
     * Serve a `SaldoRicalcoloService` per recuperare una notte iniziata l'ultimo
     * giorno del mese precedente: le sue ore post-mezzanotte ricadono nel mese
     * corrente ("le ore seguono il calendario"). Stessa proiezione di
     * `listByOperatoreInMese`.
     */
    public function findByOperatoreData(int $idOperatore, string $data): ?array
    {
        $sql = "SELECT t.data,
                       t.ore_effettive,
                       tt.ore_conteggiate,
                       tt.ora_inizio,
                       tt.ora_fine,
                       tt.is_riposo,
                       tt.is_ferie,
                       tt.is_permesso,
                       tt.is_malattia,
                       tt.is_formazione
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_operatore = :id_op
                  AND t.data = :data
                LIMIT 1";
        return $this->db->queryOne($sql, [
            'id_op' => $idOperatore,
            'data'  => $data,
        ]);
    }

    /**
     * Date già occupate da un turno per gli operatori indicati nel mese, su
     * QUALSIASI piano (l'UNIQUE (operatore, data) è globale). Il generatore le
     * salta per non violare l'unique né sovrascrivere turni esistenti.
     *
     * @param list<int> $idOperatori
     * @return array<int, list<string>> id_operatore => date Y-m-d occupate
     */
    public function dateOccupateInMese(array $idOperatori, int $anno, int $mese): array
    {
        if ($idOperatori === []) {
            return [];
        }
        $primo = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->format('Y-m-t');
        $in = implode(',', array_fill(0, count($idOperatori), '?'));
        $sql = "SELECT id_operatore, data FROM turni
                WHERE id_operatore IN ({$in}) AND data BETWEEN ? AND ?";
        $params = array_merge(
            array_map(static fn ($id) => (int) $id, $idOperatori),
            [$primo, $ultimo],
        );
        $map = [];
        foreach ($this->db->query($sql, $params) as $r) {
            $map[(int) $r['id_operatore']][] = (string) $r['data'];
        }
        return $map;
    }

    /**
     * Turni di un operatore nel periodo [inizio, fine] su QUALSIASI piano
     * (l'UNIQUE op-data è globale → cross-setting). Porta stato/anno/mese del
     * piano e il nome del setting, così il chiamante sa quali mesi ricalcolare e
     * quali piani PUBBLICATI sono stati toccati. Usato da AssenzeController per
     * svuotare i turni che cadono nel periodo di un'assenza appena creata.
     *
     * @return list<array<string,mixed>>
     */
    public function listByOperatoreInPeriodo(int $idOperatore, string $inizio, string $fine): array
    {
        $sql = "SELECT t.id, t.data, t.id_piano,
                       p.stato, p.anno, p.mese, p.nome AS piano_nome,
                       s.nome AS setting_nome
                FROM turni t
                JOIN piano_turni p ON p.id = t.id_piano
                JOIN setting s ON s.id = p.id_setting
                WHERE t.id_operatore = :id_op
                  AND t.data BETWEEN :inizio AND :fine
                ORDER BY t.data";
        return $this->db->query($sql, [
            'id_op'  => $idOperatore,
            'inizio' => $inizio,
            'fine'   => $fine,
        ]);
    }

    /**
     * Cancella i turni con gli id indicati (batch). Placeholder posizionali per
     * non riusare i named (regola PDO). Ritorna il numero di righe eliminate.
     *
     * @param list<int> $ids
     */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM turni WHERE id IN ({$in})";
        return $this->db->execute($sql, array_map(static fn ($id) => (int) $id, $ids));
    }
}
