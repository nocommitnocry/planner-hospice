<?php
declare(strict_types=1);

namespace App\Models;

final class OperatoreModel extends BaseModel
{
    protected string $table = 'operatori';

    protected array $fillable = [
        'nome',
        'cognome',
        'id_categoria',
        'id_setting',
        'ore_contrattuali_mensili',
        'email',
        'telefono',
        'note',
        'attivo',
    ];

    /**
     * Lista operatori joinata con categoria e setting, ordinata per cognome/nome.
     * Se $idSetting è valorizzato, filtra solo gli operatori "di casa" in quel setting.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCategoria(bool $soloAttivi = false, ?int $idSetting = null): array
    {
        $sql = "SELECT o.*,
                       c.nome AS categoria_nome,
                       s.codice AS setting_codice,
                       s.nome   AS setting_nome
                FROM operatori o
                JOIN categorie_operatori c ON c.id = o.id_categoria
                JOIN setting s             ON s.id = o.id_setting";
        $where = [];
        $params = [];
        if ($soloAttivi) {
            $where[] = 'o.attivo = 1';
        }
        if ($idSetting !== null) {
            $where[] = 'o.id_setting = :id_setting';
            $params['id_setting'] = $idSetting;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY o.cognome ASC, o.nome ASC";
        return $this->db->query($sql, $params);
    }
}
