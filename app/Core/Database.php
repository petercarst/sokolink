<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The single PDO connection, and the only place that opens one.
 *
 * Every setting applied here is load-bearing and documented in
 * docs/DATABASE_DESIGN.md section 11:
 *
 *   STRICT_ALL_TABLES   this MariaDB build is NOT strict by default. Without
 *                       it, 'TOOLONGVALUE' into VARCHAR(4) silently stores
 *                       'TOOL' and a bad DECIMAL silently becomes 0. For a
 *                       system that handles money that is not acceptable.
 *   time_zone '+00:00'  every DATETIME in the schema is UTC. A connection in
 *                       local time makes "delivered at" wrong by three hours
 *                       and nothing warns you.
 *   EMULATE_PREPARES    off, so values are bound server-side. With emulation
 *                       on, PDO interpolates client-side and the injection
 *                       guarantee weakens to "probably".
 *   ERRMODE_EXCEPTION   a failed write must not be a return value nobody
 *                       checks.
 *
 * Nested transaction() calls use SAVEPOINTs rather than silently joining the
 * outer transaction, so an inner failure rolls back only the inner work.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Depth of nested transaction() calls. 0 means none open. */
    private static int $depth = 0;

    /** @var list<array{sql:string,ms:float}> */
    private static array $queryLog = [];

    private static bool $logQueries = false;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('database.host', '127.0.0.1');
        $port    = (int) Config::get('database.port', 3306);
        $name    = (string) Config::get('database.database', 'sokolink');
        $charset = (string) Config::get('database.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            $pdo = new PDO(
                $dsn,
                (string) Config::get('database.username', 'root'),
                (string) Config::get('database.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            // The message can contain the username and host. Log it, do not
            // let it reach a page.
            Logger::error('Database connection failed', [
                'host'     => $host,
                'database' => $name,
                'message'  => $e->getMessage(),
            ]);

            throw new RuntimeException('The database is unavailable.', 0, $e);
        }

        $pdo->exec(
            "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
        $pdo->exec("SET SESSION time_zone='+00:00'");

        self::$pdo = $pdo;

        return self::$pdo;
    }

    /**
     * Replaces the connection. Only tests and the CLI bootstrap use this.
     */
    public static function swap(?PDO $pdo): void
    {
        self::$pdo   = $pdo;
        self::$depth = 0;
    }

    // ---- Reading ----------------------------------------------------------

    /**
     * @param  array<string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param  array<string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $bindings */
    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * First column of every row, which is what most "ids for this owner"
     * lookups actually want.
     *
     * @param  array<string,mixed> $bindings
     * @return list<mixed>
     */
    public static function column(string $sql, array $bindings = []): array
    {
        /** @var list<mixed> $values */
        $values = self::run($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }

    // ---- Writing ----------------------------------------------------------

    /**
     * Runs an INSERT and returns the new id.
     *
     * @param array<string,mixed> $data column => value
     */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        self::run($sql, $data);

        return (int) self::connection()->lastInsertId();
    }

    /**
     * Runs an UPDATE and returns the number of rows the server actually
     * changed. Callers that rely on a guard in the WHERE clause - stock
     * reservation is the important one - must check this is 1.
     *
     * @param array<string,mixed> $data      column => value
     * @param array<string,mixed> $bindings  extra bindings used by $where
     */
    public static function update(string $table, array $data, string $where, array $bindings = []): int
    {
        $sets = [];
        $bind = [];

        foreach ($data as $column => $value) {
            $sets[]             = sprintf('`%s` = :set_%s', $column, $column);
            $bind['set_' . $column] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);

        return self::run($sql, array_merge($bind, $bindings))->rowCount();
    }

    /** @param array<string,mixed> $bindings */
    public static function delete(string $table, string $where, array $bindings = []): int
    {
        return self::run(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $bindings)->rowCount();
    }

    /**
     * Any other statement. Returns affected rows.
     *
     * @param array<string,mixed> $bindings
     */
    public static function statement(string $sql, array $bindings = []): int
    {
        return self::run($sql, $bindings)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    // ---- Transactions ------------------------------------------------------

    /**
     * Runs $work inside a transaction, committing on return and rolling back on
     * any throwable. The throwable is re-thrown: swallowing it here would turn
     * a failed order into a silent no-op.
     *
     * Nesting creates a SAVEPOINT, with the standard meaning: an inner failure
     * undoes the inner work and leaves the outer transaction usable. That is
     * the point of a savepoint - "try to reserve this optional extra; if it is
     * gone, carry on with the rest of the order".
     *
     * It does mean a caller that catches an inner failure has taken
     * responsibility for deciding whether the outer work still makes sense.
     * Callers that must not proceed simply do not catch it: the throwable
     * propagates and the whole transaction rolls back.
     *
     * @template T
     * @param  callable():T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();

        if (self::$depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp' . self::$depth);
        }

        self::$depth++;
        $savepoint = 'sp' . (self::$depth - 1);

        try {
            $result = $work();
        } catch (Throwable $e) {
            self::$depth--;

            if (self::$depth === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }

        self::$depth--;

        if (self::$depth === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return $result;
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    public static function transactionDepth(): int
    {
        return self::$depth;
    }

    /**
     * Asserts a transaction is open. Services that reserve stock or write money
     * call this, because doing that work outside a transaction is a bug that
     * would otherwise only show up under load.
     */
    public static function requireTransaction(string $operation): void
    {
        if (self::$depth === 0) {
            throw new RuntimeException($operation . ' must run inside a transaction.');
        }
    }

    // ---- Diagnostics -------------------------------------------------------

    public static function enableQueryLog(bool $on = true): void
    {
        self::$logQueries = $on;
        self::$queryLog   = [];
    }

    /** @return list<array{sql:string,ms:float}> */
    public static function queryLog(): array
    {
        return self::$queryLog;
    }

    // ---- Internals ---------------------------------------------------------

    /** @param array<string,mixed> $bindings */
    private static function run(string $sql, array $bindings): PDOStatement
    {
        $pdo       = self::connection();
        $statement = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':'),
                $value,
                match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                }
            );
        }

        $start = microtime(true);
        $statement->execute();

        if (self::$logQueries) {
            self::$queryLog[] = ['sql' => $sql, 'ms' => round((microtime(true) - $start) * 1000, 2)];
        }

        return $statement;
    }
}
