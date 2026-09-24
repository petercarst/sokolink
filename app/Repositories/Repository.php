<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Base for every repository.
 *
 * Repositories are the only layer that writes SQL. Services call them;
 * controllers call services; views call neither (docs/SYSTEM_ARCHITECTURE.md
 * section 1). Keeping SQL in one layer is what makes "does anything, anywhere,
 * read another seller's orders?" a question that can be answered by reading a
 * folder.
 *
 * Two rules every subclass follows:
 *
 *   1. An owner id used for scoping is a method parameter, and it comes from
 *      the session-derived actor. A repository never reads the request.
 *   2. A scoping clause lives in the SQL, not in a filter applied afterwards.
 *      Rows a caller may not see are never fetched in the first place.
 */
abstract class Repository
{
    protected string $table = '';

    /**
     * @param  array<string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    protected function select(string $sql, array $bindings = []): array
    {
        return Database::select($sql, $bindings);
    }

    /**
     * @param  array<string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    protected function selectOne(string $sql, array $bindings = []): ?array
    {
        return Database::selectOne($sql, $bindings);
    }

    /** @param array<string,mixed> $bindings */
    protected function scalar(string $sql, array $bindings = []): mixed
    {
        return Database::scalar($sql, $bindings);
    }

    /** @param array<string,mixed> $data */
    protected function insertInto(string $table, array $data): int
    {
        return Database::insert($table, $data);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $bindings
     */
    protected function updateWhere(string $table, array $data, string $where, array $bindings = []): int
    {
        return Database::update($table, $data, $where, $bindings);
    }

    /** @param array<string,mixed> $bindings */
    protected function statement(string $sql, array $bindings = []): int
    {
        return Database::statement($sql, $bindings);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($this->table === '') {
            return null;
        }

        return $this->selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = :id', $this->table),
            ['id' => $id]
        );
    }

    public function exists(int $id): bool
    {
        if ($this->table === '') {
            return false;
        }

        return (int) $this->scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE id = :id', $this->table),
            ['id' => $id]
        ) > 0;
    }

    /**
     * Turns a page number into a LIMIT/OFFSET pair with sane bounds, so a
     * hand-typed `?page=-4&per=100000` cannot ask the database for everything.
     *
     * @return array{limit:int,offset:int,page:int,perPage:int}
     */
    protected function paginate(int $page, int $perPage, int $maxPerPage = 100): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min($perPage, $maxPerPage));

        return [
            'limit'   => $perPage,
            'offset'  => ($page - 1) * $perPage,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Builds `ORDER BY` from an allow-list. An unrecognised sort key falls back
     * to the default rather than reaching the query, because ORDER BY cannot be
     * parameterised and a caller-supplied column name is an injection.
     *
     * @param array<string,string> $allowed key => SQL fragment
     */
    protected function orderBy(?string $key, array $allowed, string $default): string
    {
        if ($key !== null && isset($allowed[$key])) {
            return $allowed[$key];
        }

        return $allowed[$default] ?? reset($allowed);
    }
}
