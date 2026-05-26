<?php
declare(strict_types=1);

namespace App\Models;

final class CategoriaOperatoreModel extends BaseModel
{
    protected string $table = 'categorie_operatori';

    protected array $fillable = [
        'nome',
        'descrizione',
        'gruppo_pianificazione',
        'ordine_visualizzazione',
    ];

    /**
     * Valori ammessi per `gruppo_pianificazione` (allineati alla ENUM della
     * migrazione 0011) e relative etichette per la UI del CRUD categorie.
     * Le etichette plurali usate nelle bande della stampa PDF vivono invece
     * nel raggruppatore (PianoPdfService).
     */
    public const GRUPPI = ['infermiere', 'oss', 'coordinatore', 'altro'];

    public const GRUPPI_LABEL = [
        'infermiere'   => 'Infermiere',
        'oss'          => 'OSS',
        'coordinatore' => 'Coordinatrice',
        'altro'        => 'Altri',
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
