<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Storico delle modifiche manuali ai saldi ore (sessione 4-ter).
 *
 * Una riga per ogni:
 *  - modifica di ore_dovute su saldo esistente (pro-rata in uscita, recupero);
 *  - "reset di verità" del saldo_progressivo (aggancio cedolino);
 *  - aggiunta esplicita di un operatore al piano (riga di tipo
 *    `aggiunta_operatore` con valori iniziali).
 *
 * Nota motivazione obbligatoria a livello applicativo (NOT NULL nel DB).
 */
final class SaldoModificaModel extends BaseModel
{
    protected string $table = 'saldo_modifiche';

    protected array $fillable = [
        'id_saldo',
        'id_utente',
        'tipo_modifica',
        'valore_precedente',
        'valore_nuovo',
        'note',
    ];

    /**
     * Storico modifiche di un saldo, dal più recente. Joinato con utenti per
     * mostrare chi ha fatto la modifica.
     *
     * @return list<array<string,mixed>>
     */
    public function listBySaldo(int $idSaldo): array
    {
        return $this->db->query(
            "SELECT sm.*,
                    u.username AS utente_username,
                    u.cognome  AS utente_cognome,
                    u.nome     AS utente_nome
             FROM saldo_modifiche sm
             LEFT JOIN utenti u ON u.id = sm.id_utente
             WHERE sm.id_saldo = :id_saldo
             ORDER BY sm.creato_il DESC",
            ['id_saldo' => $idSaldo],
        );
    }
}
