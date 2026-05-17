<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Container;
use App\Helpers\Database;
use InvalidArgumentException;

/**
 * Model base con CRUD generici.
 *
 * Sicurezza:
 * - $fillable definisce le colonne ammesse per insert/update. Qualunque chiave
 *   non in $fillable viene scartata silenziosamente (allow-list).
 * - I nomi di colonna usati nelle clausole WHERE/SET vengono validati contro
 *   $fillable + $primaryKey: questo impedisce SQL injection via array keys.
 */
abstract class BaseModel
{
    protected string $table;
    protected string $primaryKey = 'id';

    /** @var list<string> Colonne che si possono settare via create/update */
    protected array $fillable = [];

    protected Database $db;

    public function __construct()
    {
        $this->db = Container::instance()->get(Database::class);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id",
            ['id' => $id]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findAll(?string $orderBy = null, string $direction = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($orderBy !== null) {
            $this->assertColumn($orderBy);
            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        return $this->db->query($sql);
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array<string,mixed>|null
     */
    public function findOneBy(array $criteria): ?array
    {
        [$where, $params] = $this->buildWhere($criteria);
        $sql = "SELECT * FROM {$this->table} {$where} LIMIT 1";
        return $this->db->queryOne($sql, $params);
    }

    /**
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    public function findBy(array $criteria, ?string $orderBy = null, string $direction = 'ASC'): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        $sql = "SELECT * FROM {$this->table} {$where}";
        if ($orderBy !== null) {
            $this->assertColumn($orderBy);
            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        return $this->db->query($sql, $params);
    }

    /**
     * Inserisce e restituisce l'ID. Le chiavi non in $fillable vengono scartate.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $data = $this->filterFillable($data);
        if ($data === []) {
            throw new InvalidArgumentException('Nessun campo valido per la create.');
        }
        $cols = array_keys($data);
        $placeholders = array_map(fn ($c) => ":{$c}", $cols);
        $sql = "INSERT INTO {$this->table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        return $this->db->insert($sql, $data);
    }

    /**
     * Aggiorna e restituisce il numero di righe modificate.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): int
    {
        $data = $this->filterFillable($data);
        if ($data === []) {
            return 0;
        }
        $sets = array_map(fn ($c) => "{$c} = :{$c}", array_keys($data));
        $sql = "UPDATE {$this->table} SET " . implode(',', $sets) . " WHERE {$this->primaryKey} = :__id";
        $params = $data + ['__id' => $id];
        return $this->db->execute($sql, $params);
    }

    public function delete(int $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id",
            ['id' => $id]
        );
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array{0:string, 1:array<string,mixed>}
     */
    protected function buildWhere(array $criteria): array
    {
        if ($criteria === []) {
            return ['', []];
        }
        $clauses = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            $this->assertColumn($column);
            $clauses[] = "{$column} = :w_{$column}";
            $params["w_{$column}"] = $value;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function filterFillable(array $data): array
    {
        if ($this->fillable === []) {
            return $data; // model che non dichiara fillable accetta tutto (sconsigliato)
        }
        return array_intersect_key($data, array_flip($this->fillable));
    }

    protected function assertColumn(string $name): void
    {
        $allowed = array_merge($this->fillable, [$this->primaryKey, 'creato_il', 'aggiornato_il']);
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException("Colonna non ammessa: {$name}");
        }
    }
}
