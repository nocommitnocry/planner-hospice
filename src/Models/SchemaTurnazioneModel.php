<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Schemi di turnazione (sessione 6).
 *
 * Uno schema descrive una sequenza ripetuta di turni come DATI:
 * - famiglia 'ciclico': periodo di N giorni, basato sulla POSIZIONE nel ciclo
 *   (Hospice infermieri/OSS). L'operatore avanza di una posizione al giorno.
 * - famiglia 'settimanale': periodo 7, basato sul GIORNO DELLA SETTIMANA
 *   (coordinatrice Hospice, tutto UCP-DOM).
 *
 * I passi veri e propri (con tipo turno + ore) stanno in `schema_passi`
 * (vedi SchemaPassoModel). Read-only dal punto di vista applicativo: gli
 * schemi si seedano via migrazione, non c'è CRUD.
 */
final class SchemaTurnazioneModel extends BaseModel
{
    protected string $table = 'schemi_turnazione';

    protected array $fillable = [
        'codice',
        'nome',
        'id_setting',
        'famiglia',
        'periodo_giorni',
        'attivo',
    ];

    /**
     * Schemi attivi, opzionalmente filtrati per setting. JOIN setting per
     * codice/nome leggibili.
     *
     * @return list<array<string,mixed>>
     */
    public function listAttivi(?int $idSetting = null): array
    {
        $sql = "SELECT s.*, st.codice AS setting_codice, st.nome AS setting_nome
                FROM {$this->table} s
                JOIN setting st ON st.id = s.id_setting
                WHERE s.attivo = 1";
        $params = [];
        if ($idSetting !== null) {
            $sql .= " AND s.id_setting = :id_setting";
            $params['id_setting'] = $idSetting;
        }
        $sql .= " ORDER BY st.ordine_visualizzazione ASC, s.codice ASC";
        return $this->db->query($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public function findByCodice(string $codice): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM {$this->table} WHERE codice = :codice",
            ['codice' => $codice]
        );
    }
}
