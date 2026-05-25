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
        'attivo',
        'id_setting',
    ];

    /** @return list<array<string,mixed>> */
    public function listOrdered(): array
    {
        return $this->findAll('priorita', 'ASC');
    }

    /**
     * Tipi turno che rappresentano un'assenza: hanno almeno uno dei flag
     * is_ferie / is_permesso / is_malattia / esclude_pianificazione. Solo i
     * tipi attivi (i ritirati non si assegnano più).
     *
     * Usato dal dropdown "Tipo di assenza" in `/assenze`: i tipi di lavoro
     * (M, P, N, S, R, formazione…) non hanno senso lì. Le assenze valgono per
     * tutti i setting, quindi qui niente filtro per `id_setting`.
     *
     * @return list<array<string,mixed>>
     */
    public function listSoloAssenze(): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE attivo = 1
                  AND (is_ferie = 1
                       OR is_permesso = 1
                       OR is_malattia = 1
                       OR esclude_pianificazione = 1)
                ORDER BY priorita ASC";
        return $this->db->query($sql);
    }

    /**
     * Tipi turno di LAVORO assegnabili da "assegna turno": il complemento di
     * `listSoloAssenze` (nessuno dei 4 flag-assenza), solo attivi, e pertinenti
     * al setting del piano. `id_setting = NULL` significa "vale per entrambi"
     * (R, Rec, Corso). `R`/`S` (riposo/smonto) restano: non sono assenze.
     *
     * Se `$idSetting` è null nessun filtro di setting (utile per usi generici).
     *
     * @return list<array<string,mixed>>
     */
    public function listSoloLavoro(?int $idSetting = null): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE attivo = 1
                  AND is_ferie = 0
                  AND is_permesso = 0
                  AND is_malattia = 0
                  AND esclude_pianificazione = 0";
        $params = [];
        if ($idSetting !== null) {
            $sql .= " AND (id_setting = :setting OR id_setting IS NULL)";
            $params['setting'] = $idSetting;
        }
        $sql .= " ORDER BY priorita ASC";
        return $this->db->query($sql, $params);
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
