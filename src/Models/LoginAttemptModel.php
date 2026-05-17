<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Container;
use App\Helpers\Database;

/**
 * Tracking dei tentativi di login falliti, separato per username e per IP.
 *
 * Niente FK a utenti.id: vogliamo poter contare anche tentativi su username
 * inesistenti (anti-enumeration), e gli IP non hanno una tabella di pertinenza.
 */
final class LoginAttemptModel
{
    private Database $db;
    private string $table = 'login_attempts';

    public function __construct()
    {
        $this->db = Container::instance()->get(Database::class);
    }

    /**
     * Incrementa il contatore per (identifier, type) o lo crea a 1.
     */
    public function register(string $identifier, string $type): void
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->db->queryOne(
            "SELECT id, tentativi FROM {$this->table} WHERE identifier = :id AND type = :t",
            ['id' => $identifier, 't' => $type]
        );

        if ($existing !== null) {
            $this->db->execute(
                "UPDATE {$this->table}
                    SET tentativi = tentativi + 1, ultimo_tentativo = :now
                  WHERE id = :id",
                ['now' => $now, 'id' => (int) $existing['id']]
            );
        } else {
            $this->db->insert(
                "INSERT INTO {$this->table} (identifier, type, tentativi, primo_tentativo, ultimo_tentativo)
                 VALUES (:id, :t, 1, :now, :now)",
                ['id' => $identifier, 't' => $type, 'now' => $now]
            );
        }
    }

    public function reset(string $identifier, string $type): void
    {
        $this->db->execute(
            "DELETE FROM {$this->table} WHERE identifier = :id AND type = :t",
            ['id' => $identifier, 't' => $type]
        );
    }

    /**
     * Restituisce [tentativi, ultimo_tentativo] o null se nessun record.
     *
     * @return array{tentativi:int, ultimo_tentativo:string}|null
     */
    public function status(string $identifier, string $type): ?array
    {
        $row = $this->db->queryOne(
            "SELECT tentativi, ultimo_tentativo FROM {$this->table}
             WHERE identifier = :id AND type = :t",
            ['id' => $identifier, 't' => $type]
        );
        if ($row === null) {
            return null;
        }
        return [
            'tentativi'        => (int) $row['tentativi'],
            'ultimo_tentativo' => (string) $row['ultimo_tentativo'],
        ];
    }

    /**
     * Conteggia se l'identifier è bloccato. Se il lockout è scaduto pulisce
     * automaticamente il record e restituisce false.
     */
    public function isLocked(string $identifier, string $type, int $maxAttempts, int $lockoutMinutes): bool
    {
        $status = $this->status($identifier, $type);
        if ($status === null) {
            return false;
        }
        if ($status['tentativi'] < $maxAttempts) {
            return false;
        }
        $lockoutEnd = strtotime($status['ultimo_tentativo']) + ($lockoutMinutes * 60);
        if (time() < $lockoutEnd) {
            return true;
        }
        // Lockout scaduto: pulisci
        $this->reset($identifier, $type);
        return false;
    }
}
