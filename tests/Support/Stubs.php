<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Support;

use RuntimeException;
use Throwable;

class FakeResource
{
    public FakeConnection $connection;

    public function __construct()
    {
        $this->connection = new FakeConnection();
    }

    public function getConnection(string $name): FakeConnection
    {
        return $this->connection;
    }

    public function getTableName(string $alias): string
    {
        $map = [
            'hirale_asyncindex/full_run' => 'hirale_asyncindex_full_run',
            'hirale_asyncindex/process_state' => 'hirale_asyncindex_process_state',
            'index/process_event' => 'index_process_event',
            'index/process' => 'index_process',
            'catalog/product' => 'catalog_product_entity',
        ];

        return $map[$alias] ?? $alias;
    }
}

class FakeConnection
{
    /** @var list<array{table:string,values:array<string, mixed>,where:mixed}> */
    public array $updates = [];

    /** @var list<array{table:string,values:array<string, mixed>}> */
    public array $inserts = [];

    /** @var list<list<array<string, mixed>>> */
    public array $fetchAllResponses = [];

    public string $lastFetchAllSql = '';
    public string $lastFetchRowSql = '';
    public int $updateResult = 0;
    public int $insertId = 0;
    public int $transactionDepth = 0;

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed>|string $where
     */
    public function update(string $table, array $values, $where = ''): int
    {
        $this->updates[] = ['table' => $table, 'values' => $values, 'where' => $where];
        return $this->updateResult;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(string $table, array $values): int
    {
        $this->inserts[] = ['table' => $table, 'values' => $values];
        return 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql): array
    {
        $this->lastFetchAllSql = $sql;
        $next = array_shift($this->fetchAllResponses);
        return $next !== null ? $next : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRow(string $sql): array
    {
        $this->lastFetchRowSql = $sql;
        $next = array_shift($this->fetchAllResponses);
        if ($next !== null && isset($next[0]) && is_array($next[0])) {
            return $next[0];
        }
        return [];
    }

    public function fetchOne(string $sql): int
    {
        return 0;
    }

    /**
     * @return list<int>
     */
    public function fetchCol(string $sql): array
    {
        return [];
    }

    /**
     * @param mixed $value
     */
    public function quote($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'quote'], $value));
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    public function beginTransaction(): void
    {
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth--;
        }
    }

    public function lastInsertId(string $table, string $col): int
    {
        return $this->insertId;
    }
}
