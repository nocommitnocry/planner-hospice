<?php
declare(strict_types=1);

namespace App\Models;

final class CategoriaOperatoreModel extends BaseModel
{
    protected string $table = 'categorie_operatori';

    protected array $fillable = [
        'nome',
        'descrizione',
        'ordine_visualizzazione',
    ];

    /** @return list<array<string,mixed>> */
    public function listOrdered(): array
    {
        return $this->findAll('ordine_visualizzazione', 'ASC');
    }

    public function existsByName(string $nome, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE nome = :nome";
        $params = ['nome' => $nome];
        if ($excludeId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = $excludeId;
        }
        return $this->db->queryScalar($sql, $params) !== null;
    }
}
