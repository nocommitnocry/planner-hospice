<?php
declare(strict_types=1);

namespace App\Models;

final class SaldoOreModel extends BaseModel
{
    protected string $table = 'saldo_ore';

    protected array $fillable = [
        'id_operatore',
        'anno',
        'mese',
        'ore_dovute',
        'ore_lavorate',
        'ore_ferie',
        'ore_permessi',
        'ore_malattia',
        'ore_formazione',
        'saldo_mese',
        'saldo_progressivo',
        'note',
    ];

    /**
     * Saldi del mese joinati con dati operatore + categoria, ordinati per cognome/nome.
     * Se $idSetting è valorizzato, ritorna solo i saldi degli operatori "di casa"
     * in quel setting (i saldi sono unici per (op, anno, mese) e cumulano i turni
     * di entrambi i setting, ma quando li elenchiamo per un piano filtriamo per
     * setting di appartenenza dell'operatore).
     *
     * @return list<array<string,mixed>>
     */
    public function listByAnnoMese(int $anno, int $mese, ?int $idSetting = null): array
    {
        $sql = "SELECT s.*,
                       o.nome AS operatore_nome,
                       o.cognome AS operatore_cognome,
                       o.id_setting AS operatore_id_setting,
                       c.nome AS categoria_nome,
                       c.ordine_visualizzazione AS categoria_ordine
                FROM saldo_ore s
                JOIN operatori o ON o.id = s.id_operatore
                JOIN categorie_operatori c ON c.id = o.id_categoria
                WHERE s.anno = :anno AND s.mese = :mese";
        $params = ['anno' => $anno, 'mese' => $mese];
        if ($idSetting !== null) {
            $sql .= " AND o.id_setting = :id_setting";
            $params['id_setting'] = $idSetting;
        }
        $sql .= " ORDER BY c.ordine_visualizzazione ASC, o.cognome ASC, o.nome ASC";
        return $this->db->query($sql, $params);
    }

    /**
     * Saldo progressivo del mese precedente per un operatore.
     * Ritorna 0.00 se non esiste un saldo precedente.
     */
    public function getProgressivoPrevious(int $idOperatore, int $anno, int $mese): string
    {
        $prevAnno = $mese === 1 ? $anno - 1 : $anno;
        $prevMese = $mese === 1 ? 12 : $mese - 1;
        $value = $this->db->queryScalar(
            "SELECT saldo_progressivo FROM saldo_ore
             WHERE id_operatore = :id AND anno = :anno AND mese = :mese",
            ['id' => $idOperatore, 'anno' => $prevAnno, 'mese' => $prevMese]
        );
        return $value !== null ? (string) $value : '0.00';
    }

    /**
     * Elimina i saldi del mese per gli operatori inclusi nel piano $idPiano,
     * SALVO quelli che compaiono anche in altri piani dello stesso mese.
     *
     * Usato dalla destroy del piano in bozza (4-ter): il saldo è cross-setting
     * e unico per (op, anno, mese), quindi va tenuto se serve a un altro piano
     * dello stesso mese. La lista degli "operatori da escludere" arriva dal
     * PianoOperatoreModel.
     *
     * @param list<int> $operatoriDaEscludere
     */
    public function deleteByAnnoMeseEscludendoOperatori(int $anno, int $mese, int $idPiano, array $operatoriDaEscludere): int
    {
        // Operatori del piano: presi dalla tabella di appartenenza esplicita.
        $opPianoRows = $this->db->query(
            "SELECT id_operatore FROM piano_operatori WHERE id_piano = :id_piano",
            ['id_piano' => $idPiano],
        );
        $opPiano = array_map(static fn ($r) => (int) $r['id_operatore'], $opPianoRows);
        $opDaEliminare = array_values(array_diff($opPiano, $operatoriDaEscludere));
        if ($opDaEliminare === []) {
            return 0;
        }

        // Costruzione placeholder posizionali: niente named ripetuti
        // (vedi feedback PDO: EMULATE_PREPARES=false).
        $placeholders = implode(',', array_fill(0, count($opDaEliminare), '?'));
        $params = array_merge([$anno, $mese], $opDaEliminare);
        return $this->db->execute(
            "DELETE FROM saldo_ore WHERE anno = ? AND mese = ? AND id_operatore IN ({$placeholders})",
            $params,
        );
    }
}
