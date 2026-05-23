<?php
declare(strict_types=1);

namespace App\Models;

final class TipoTurnoModel extends BaseModel
{
    protected string $table = 'tipi_turno';

    protected array $fillable = [
        'codice',
        'descrizione',
        'ora_inizio',
        'ora_fine',
        'colore',
        'ore_conteggiate',
        'priorita',
        'is_riposo',
        'is_ferie',
        'is_permesso',
        'is_malattia',
        'is_formazione',
        'esclude_pianificazione',
        'schema_ore',
    ];

    /** @return list<array<string,mixed>> */
    public function listOrdered(): array
    {
        return $this->findAll('priorita', 'ASC');
    }

    /**
     * Tipi turno che rappresentano un'assenza: hanno almeno uno dei flag
     * is_ferie / is_permesso / is_malattia / esclude_pianificazione.
     *
     * Usato dal dropdown "Tipo di assenza" in `/assenze`: i tipi di lavoro
     * (M, P, N, S, R, formazione…) non hanno senso lì.
     *
     * @return list<array<string,mixed>>
     */
    public function listSoloAssenze(): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE is_ferie = 1
                   OR is_permesso = 1
                   OR is_malattia = 1
                   OR esclude_pianificazione = 1
                ORDER BY priorita ASC";
        return $this->db->query($sql);
    }

    public function existsByCodice(string $codice, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE codice = :codice";
        $params = ['codice' => $codice];
        if ($excludeId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = $excludeId;
        }
        return $this->db->queryScalar($sql, $params) !== null;
    }
}
