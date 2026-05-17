<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Modello tabella `utenti`. La gestione password è delegata a AuthService:
 * qui ci sono solo CRUD e lookup.
 */
final class UtenteModel extends BaseModel
{
    protected string $table = 'utenti';

    protected array $fillable = [
        'username',
        'password',     // hash; chi chiama deve averla già passata a password_hash
        'nome',
        'cognome',
        'email',
        'ruolo',
        'id_setting',   // NULL = utente globale (admin/visualizzatore senza setting di default)
        'attivo',
        'ultimo_accesso',
    ];

    public function findByUsername(string $username): ?array
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function existsByUsername(string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT 1 FROM {$this->table} WHERE username = :u";
        $params = ['u' => $username];
        if ($excludeId !== null) {
            $sql .= " AND id <> :id";
            $params['id'] = $excludeId;
        }
        return $this->db->queryScalar($sql, $params) !== null;
    }

    /** @return list<array<string,mixed>> */
    public function listAllOrdered(): array
    {
        return $this->db->query(
            "SELECT u.*, s.codice AS setting_codice, s.nome AS setting_nome
             FROM utenti u
             LEFT JOIN setting s ON s.id = u.id_setting
             ORDER BY u.username ASC"
        );
    }

    public function aggiornaUltimoAccesso(int $id): void
    {
        $this->update($id, ['ultimo_accesso' => date('Y-m-d H:i:s')]);
    }
}
