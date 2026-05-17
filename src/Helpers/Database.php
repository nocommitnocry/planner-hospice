<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Wrapper PDO con metodi di convenienza per query/insert/update.
 *
 * - Prepared statement obbligatori.
 * - Modalità ERRMODE_EXCEPTION attiva (errori = eccezioni).
 * - Niente emulated prepares.
 */
final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $host = (string) Config::env('DB_HOST', '127.0.0.1');
        $port = (string) Config::env('DB_PORT', '3306');
        $name = (string) Config::env('DB_NAME', '');
        $user = (string) Config::env('DB_USER', '');
        $pass = (string) Config::env('DB_PASSWORD', '');
        $charset = (string) Config::env('DB_CHARSET', 'utf8mb4');

        if ($name === '' || $user === '') {
            throw new RuntimeException('Configurazione database mancante: impostare DB_NAME e DB_USER in .env');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            Logger::get()->error('Connessione DB fallita', ['message' => $e->getMessage()]);
            throw new RuntimeException('Errore di connessione al database.', 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql, $params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepare($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->prepare($sql, $params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql, $params);
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->prepare($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void           { $this->pdo->commit(); }
    public function rollback(): void         { $this->pdo->rollBack(); }

    /**
     * Esegue $callback dentro una transazione con commit/rollback automatici.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $params */
    private function prepare(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
