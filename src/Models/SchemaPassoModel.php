<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Passi di uno schema di turnazione (sessione 6).
 *
 * Ogni passo è una posizione dello schema con:
 * - `id_tipo_turno`: il tipo proposto dal generatore nella cella (NULL = nessun turno).
 * - `ore_lavorate`: ore_effettive da scrivere sul turno generato (worked, con
 *   eventuale vestizione per Hospice; varia per giorno negli schemi UCP-DOM).
 * - `ore_assenza`: ore conteggiate se quella posizione cade in un'assenza
 *   (base, senza vestizione — sono le tabelle ferie dell'Excel di sessione 6).
 *
 * `posizione` è 0..periodo-1 per gli schemi ciclici, 0=lunedì..6=domenica per
 * i settimanali.
 */
final class SchemaPassoModel extends BaseModel
{
    protected string $table = 'schema_passi';

    protected array $fillable = [
        'id_schema',
        'posizione',
        'id_tipo_turno',
        'ore_lavorate',
        'ore_assenza',
    ];

    /**
     * Passi di uno schema, ordinati per posizione. LEFT JOIN tipi_turno
     * (il tipo può essere NULL) per esporre codice/colore/descrizione utili
     * al generatore e all'eventuale anteprima.
     *
     * @return list<array<string,mixed>>
     */
    public function listBySchema(int $idSchema): array
    {
        $sql = "SELECT sp.id, sp.id_schema, sp.posizione, sp.id_tipo_turno,
                       sp.ore_lavorate, sp.ore_assenza,
                       t.codice AS tipo_codice, t.descrizione AS tipo_descrizione,
                       t.colore AS tipo_colore
                FROM {$this->table} sp
                LEFT JOIN tipi_turno t ON t.id = sp.id_tipo_turno
                WHERE sp.id_schema = :id_schema
                ORDER BY sp.posizione ASC";
        return $this->db->query($sql, ['id_schema' => $idSchema]);
    }

    /**
     * Il passo a una data posizione di uno schema (per il conteggio assenze:
     * dato il giorno → posizione → ore_assenza).
     *
     * @return array<string,mixed>|null
     */
    public function findBySchemaPosizione(int $idSchema, int $posizione): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM {$this->table} WHERE id_schema = :id_schema AND posizione = :posizione",
            ['id_schema' => $idSchema, 'posizione' => $posizione]
        );
    }
}
