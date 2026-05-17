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
    ];

    /** @return list<array<string,mixed>> */
    public function listOrdered(): array
    {
        return $this->findAll('priorita', 'ASC');
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
