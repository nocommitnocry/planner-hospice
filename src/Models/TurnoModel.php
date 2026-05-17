<?php
declare(strict_types=1);

namespace App\Models;

final class TurnoModel extends BaseModel
{
    protected string $table = 'turni';

    protected array $fillable = [
        'id_piano',
        'id_operatore',
        'data',
        'id_tipo_turno',
        'note',
    ];

    /**
     * Tutti i turni di un piano con i campi del tipo turno necessari alla UI.
     *
     * @return list<array<string,mixed>>
     */
    public function listByPiano(int $idPiano): array
    {
        $sql = "SELECT t.id, t.id_piano, t.id_operatore, t.data, t.id_tipo_turno, t.note,
                       tt.codice AS tipo_codice,
                       tt.descrizione AS tipo_descrizione,
                       tt.colore AS tipo_colore
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_piano = :id_piano
                ORDER BY t.data ASC, t.id_operatore ASC";
        return $this->db->query($sql, ['id_piano' => $idPiano]);
    }

    /**
     * Turno specifico nel piano per (operatore, data). Ritorna anche dati tipo turno
     * (servono per messaggi di errore e UI di edit).
     *
     * @return array<string,mixed>|null
     */
    public function findInPianoByOperatoreData(int $idPiano, int $idOperatore, string $data): ?array
    {
        $sql = "SELECT t.*, tt.codice AS tipo_codice, tt.descrizione AS tipo_descrizione
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_piano = :id_piano
                  AND t.id_operatore = :id_op
                  AND t.data = :data
                LIMIT 1";
        return $this->db->queryOne($sql, [
            'id_piano' => $idPiano,
            'id_op'    => $idOperatore,
            'data'     => $data,
        ]);
    }

    /**
     * Turni di un operatore in un dato mese, con i flag/ore del tipo turno
     * necessari al ricalcolo del saldo (lavorate / ferie / permessi / malattia /
     * formazione / riposo).
     *
     * @return list<array<string,mixed>>
     */
    public function listByOperatoreInMese(int $idOperatore, int $anno, int $mese): array
    {
        $primo = sprintf('%04d-%02d-01', $anno, $mese);
        $ultimo = (new \DateTimeImmutable($primo))->format('Y-m-t');

        $sql = "SELECT t.data,
                       tt.ore_conteggiate,
                       tt.is_riposo,
                       tt.is_ferie,
                       tt.is_permesso,
                       tt.is_malattia,
                       tt.is_formazione
                FROM turni t
                JOIN tipi_turno tt ON tt.id = t.id_tipo_turno
                WHERE t.id_operatore = :id_op
                  AND t.data BETWEEN :primo AND :ultimo";
        return $this->db->query($sql, [
            'id_op'  => $idOperatore,
            'primo'  => $primo,
            'ultimo' => $ultimo,
        ]);
    }
}
