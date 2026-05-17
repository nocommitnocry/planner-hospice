<?php
declare(strict_types=1);

namespace App\Models;

final class PianoTurnoModel extends BaseModel
{
    protected string $table = 'piano_turni';

    protected array $fillable = [
        'anno',
        'mese',
        'id_setting',
        'nome',
        'stato',
        'creato_da',
        'pubblicato_il',
    ];

    public const STATI = ['bozza', 'pubblicato', 'archiviato'];

    /**
     * Elenco piani con nome utente che li ha creati, ordinati dal più recente.
     * Opzionalmente filtra per stato e/o setting.
     *
     * @return list<array<string,mixed>>
     */
    public function listOrdered(?string $stato = null, ?int $idSetting = null): array
    {
        $sql = "SELECT p.*,
                       u.username AS creato_da_username,
                       u.nome AS creato_da_nome,
                       u.cognome AS creato_da_cognome,
                       s.codice AS setting_codice,
                       s.nome   AS setting_nome,
                       (SELECT COUNT(*) FROM turni t WHERE t.id_piano = p.id) AS num_turni
                FROM piano_turni p
                LEFT JOIN utenti u ON u.id = p.creato_da
                JOIN setting s     ON s.id = p.id_setting";
        $where = [];
        $params = [];
        if ($stato !== null) {
            $where[] = 'p.stato = :stato';
            $params['stato'] = $stato;
        }
        if ($idSetting !== null) {
            $where[] = 'p.id_setting = :id_setting';
            $params['id_setting'] = $idSetting;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.anno DESC, p.mese DESC, s.ordine_visualizzazione ASC";
        return $this->db->query($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public function findByAnnoMeseSetting(int $anno, int $mese, int $idSetting): ?array
    {
        return $this->findOneBy(['anno' => $anno, 'mese' => $mese, 'id_setting' => $idSetting]);
    }

    /** @return array<string,mixed>|null */
    public function findWithSetting(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT p.*, s.codice AS setting_codice, s.nome AS setting_nome
             FROM piano_turni p
             JOIN setting s ON s.id = p.id_setting
             WHERE p.id = :id",
            ['id' => $id],
        );
    }

    public function countTurni(int $idPiano): int
    {
        return (int) $this->db->queryScalar(
            "SELECT COUNT(*) FROM turni WHERE id_piano = :id",
            ['id' => $idPiano]
        );
    }
}
