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
        'data_assunzione',
        'data_cessazione',
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

    /**
     * Un singolo operatore con il nome della categoria e il codice/nome del
     * setting "di casa" già joinati. Serve a chi deve risolvere lo schema di
     * turnazione (es. SchemaOreService) senza fare la join a mano.
     *
     * @return array<string,mixed>|null
     */
    public function findConSettingCategoria(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT o.*,
                    c.nome AS categoria_nome,
                    s.codice AS setting_codice,
                    s.nome   AS setting_nome
             FROM operatori o
             JOIN categorie_operatori c ON c.id = o.id_categoria
             JOIN setting s             ON s.id = o.id_setting
             WHERE o.id = :id",
            ['id' => $id],
        );
    }

    /**
     * Operatori "in servizio" in un mese: attivi, assunti entro l'ultimo del
     * mese (o senza data_assunzione), non cessati prima del primo del mese
     * (o senza data_cessazione). Se $idSetting è valorizzato, filtra il
     * setting "di casa".
     *
     * @return list<array<string,mixed>>
     */
    public function findInServizioNelMese(int $anno, int $mese, ?int $idSetting = null): array
    {
        $primo  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->modify('last day of this month')->format('Y-m-d');
        $sql = "SELECT * FROM operatori
                WHERE attivo = 1
                  AND (data_assunzione IS NULL OR data_assunzione <= :ultimo)
                  AND (data_cessazione IS NULL OR data_cessazione >= :primo)";
        $params = ['ultimo' => $ultimo, 'primo' => $primo];
        if ($idSetting !== null) {
            $sql .= ' AND id_setting = :id_setting';
            $params['id_setting'] = $idSetting;
        }
        $sql .= ' ORDER BY cognome ASC, nome ASC';
        return $this->db->query($sql, $params);
    }

    /**
     * Operatori candidati ad essere aggiunti a un piano in itinere: in servizio
     * nel mese del piano e non ancora presenti in `piano_operatori`. Include
     * operatori dell'altro setting (è il caso d'uso principale: spostamenti
     * brevi/lunghi cross-setting).
     *
     * @return list<array<string,mixed>>
     */
    public function findCandidatiAggiunta(int $idPiano, int $anno, int $mese): array
    {
        $primo  = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->modify('last day of this month')->format('Y-m-d');
        return $this->db->query(
            "SELECT o.*,
                    c.nome AS categoria_nome,
                    s.codice AS setting_codice,
                    s.nome   AS setting_nome
             FROM operatori o
             JOIN categorie_operatori c ON c.id = o.id_categoria
             JOIN setting s             ON s.id = o.id_setting
             WHERE o.attivo = 1
               AND (o.data_assunzione IS NULL OR o.data_assunzione <= :ultimo)
               AND (o.data_cessazione IS NULL OR o.data_cessazione >= :primo)
               AND o.id NOT IN (
                   SELECT id_operatore FROM piano_operatori WHERE id_piano = :id_piano
               )
             ORDER BY o.cognome ASC, o.nome ASC",
            ['ultimo' => $ultimo, 'primo' => $primo, 'id_piano' => $idPiano],
        );
    }
}
