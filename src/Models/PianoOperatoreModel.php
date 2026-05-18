<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Appartenenza esplicita di un operatore a un piano turno.
 *
 * La riga viene creata:
 *  - dalla create del piano per ogni operatore incluso automaticamente (di casa
 *    nel setting, in servizio nel mese): `aggiunto_manualmente = 0`;
 *  - dall'azione "Aggiungi operatore al piano" (sessione 4-ter) per operatori
 *    in itinere — anche cross-setting: `aggiunto_manualmente = 1`.
 *
 * Il record `saldo_ore` resta legato a (operatore, anno, mese) e quindi è
 * unico cross-setting; questa tabella dice "in quali piani guardare per
 * questo operatore" e abilita il filtro per piano nelle viste calendario.
 */
final class PianoOperatoreModel extends BaseModel
{
    protected string $table = 'piano_operatori';

    protected array $fillable = [
        'id_piano',
        'id_operatore',
        'aggiunto_manualmente',
        'aggiunto_da',
        'note_aggiunta',
    ];

    /**
     * Lista degli operatori inclusi nel piano, joinata con categoria, setting e
     * il saldo ore del mese (anno/mese vengono presi dal piano). Ordinata per
     * categoria → cognome → nome.
     *
     * @return list<array<string,mixed>>
     */
    public function listInPiano(int $idPiano, int $anno, int $mese): array
    {
        return $this->db->query(
            "SELECT po.id_piano,
                    po.aggiunto_manualmente,
                    po.note_aggiunta,
                    po.aggiunto_da,
                    o.id   AS id_operatore,
                    o.nome AS operatore_nome,
                    o.cognome AS operatore_cognome,
                    o.id_setting AS operatore_id_setting,
                    c.nome AS categoria_nome,
                    c.ordine_visualizzazione AS categoria_ordine,
                    s.codice AS setting_codice,
                    s.nome   AS setting_nome,
                    sal.id                AS saldo_id,
                    sal.ore_dovute        AS ore_dovute,
                    sal.ore_lavorate      AS ore_lavorate,
                    sal.ore_ferie         AS ore_ferie,
                    sal.ore_permessi      AS ore_permessi,
                    sal.ore_malattia      AS ore_malattia,
                    sal.ore_formazione    AS ore_formazione,
                    sal.saldo_mese        AS saldo_mese,
                    sal.saldo_progressivo AS saldo_progressivo
             FROM piano_operatori po
             JOIN operatori o            ON o.id = po.id_operatore
             JOIN categorie_operatori c  ON c.id = o.id_categoria
             JOIN setting s              ON s.id = o.id_setting
             LEFT JOIN saldo_ore sal     ON sal.id_operatore = po.id_operatore
                                          AND sal.anno = :anno
                                          AND sal.mese = :mese
             WHERE po.id_piano = :id_piano
             ORDER BY c.ordine_visualizzazione ASC, o.cognome ASC, o.nome ASC",
            ['id_piano' => $idPiano, 'anno' => $anno, 'mese' => $mese],
        );
    }

    /** Verifica appartenenza (op, piano). Usato dai check di sicurezza nei turni. */
    public function isInPiano(int $idPiano, int $idOperatore): bool
    {
        return $this->findOneBy([
            'id_piano'     => $idPiano,
            'id_operatore' => $idOperatore,
        ]) !== null;
    }

    /** @return array<string,mixed>|null */
    public function findInPiano(int $idPiano, int $idOperatore): ?array
    {
        return $this->findOneBy([
            'id_piano'     => $idPiano,
            'id_operatore' => $idOperatore,
        ]);
    }

    /**
     * Operatori dello stesso (anno, mese) che sono in piani DIVERSI da quello
     * dato. Usato dalla destroy per decidere quali saldi tenere.
     *
     * @return list<int> id_operatore
     */
    public function listOperatoriInAltriPianiDelMese(int $idPianoEscluso, int $anno, int $mese): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT po.id_operatore
             FROM piano_operatori po
             JOIN piano_turni p ON p.id = po.id_piano
             WHERE p.anno = :anno AND p.mese = :mese AND po.id_piano <> :escluso",
            ['anno' => $anno, 'mese' => $mese, 'escluso' => $idPianoEscluso],
        );
        return array_map(static fn ($r) => (int) $r['id_operatore'], $rows);
    }

    /**
     * Id degli operatori inclusi in un piano (lista piatta).
     *
     * Va letta PRIMA di `delete($idPiano)` perché il CASCADE su `fk_piano_op_piano`
     * pulisce `piano_operatori` insieme al piano. Usata dalla destroy per
     * iterare gli operatori e ripulire i saldi orfani.
     *
     * @return list<int> id_operatore
     */
    public function listIdOperatoriByPiano(int $idPiano): array
    {
        $rows = $this->db->query(
            "SELECT id_operatore FROM piano_operatori WHERE id_piano = :id_piano",
            ['id_piano' => $idPiano],
        );
        return array_map(static fn ($r) => (int) $r['id_operatore'], $rows);
    }

    /**
     * Conta i turni di un operatore in un piano specifico. Usato per decidere
     * se l'op può essere rimosso (no turni residui).
     */
    public function countTurniOperatoreInPiano(int $idPiano, int $idOperatore): int
    {
        $v = $this->db->queryScalar(
            "SELECT COUNT(*) FROM turni WHERE id_piano = :id_piano AND id_operatore = :id_op",
            ['id_piano' => $idPiano, 'id_op' => $idOperatore],
        );
        return (int) ($v ?? 0);
    }
}
