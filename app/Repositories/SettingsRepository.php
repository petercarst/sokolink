<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Audit;

/**
 * Operator-changeable business rules.
 *
 * What belongs here: how long an unpaid order survives, how many days a
 * collection code stays valid, the reminder frequency cap. Things an operator
 * should be able to change without a deployment.
 *
 * What does NOT belong here, and is refused below: anything secret. Database
 * credentials, the mail password and the webhook secret live in `.env`, outside
 * the web root and outside git. A settings table is readable by anyone who
 * reaches the admin screen or the database, which is exactly the wrong place
 * for a credential.
 */
final class SettingsRepository extends Repository
{
    protected string $table = 'settings';

    /** @var array<string,mixed>|null per-request cache */
    private static ?array $cache = null;

    /**
     * Keys that may never be stored here. The check is on write, so a future
     * admin screen cannot be used to smuggle a credential into the database.
     *
     * @var list<string>
     */
    private const FORBIDDEN_FRAGMENTS = ['password', 'secret', 'token', 'api_key', 'private_key', 'credential'];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    public function getDecimal(string $key, string $default = '0.00'): string
    {
        $value = $this->get($key);

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : $default;
    }

    /**
     * Every setting, typed. Cached for the request because the reminder
     * scheduler reads a handful of these once per candidate and the table is
     * tiny.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $rows = $this->select('SELECT setting_key, setting_value, value_type FROM settings');

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = $this->cast(
                (string) $row['setting_value'],
                (string) $row['value_type']
            );
        }

        self::$cache = $out;

        return $out;
    }

    /**
     * Settings grouped for the admin screen, with their descriptions.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function grouped(): array
    {
        $rows = $this->select(
            "SELECT s.setting_key, s.setting_value, s.value_type, s.setting_group, s.description,
                    s.updated_at, CONCAT(u.first_name, ' ', u.last_name) AS updated_by_name
               FROM settings s
               LEFT JOIN users u ON u.id = s.updated_by
              ORDER BY s.setting_group, s.setting_key"
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['setting_group']][] = $row;
        }

        return $grouped;
    }

    /**
     * Updates a setting and records who changed it.
     *
     * Only an existing key can be updated. Creating settings at runtime would
     * mean a typo silently becomes a new setting that nothing reads, and the
     * operator would see their change "save" and do nothing.
     *
     * @return bool false when the key does not exist
     */
    public function set(string $key, string $value, int $actorUserId): bool
    {
        $this->assertNotSecret($key);

        $before = $this->selectOne(
            'SELECT setting_value FROM settings WHERE setting_key = :k',
            ['k' => $key]
        );

        if ($before === null) {
            return false;
        }

        $changed = $this->statement(
            'UPDATE settings
                SET setting_value = :v, updated_by = :by, updated_at = UTC_TIMESTAMP()
              WHERE setting_key = :k',
            ['v' => mb_substr($value, 0, 500), 'by' => $actorUserId, 'k' => $key]
        );

        if ($changed > 0) {
            self::$cache = null;

            Audit::changed(
                'settings.updated',
                'setting',
                $key,
                ['value' => $before['setting_value']],
                ['value' => $value]
            );
        }

        return true;
    }

    /** Drops the per-request cache. Tests and long-running CLI tasks use it. */
    public static function forgetCache(): void
    {
        self::$cache = null;
    }

    private function assertNotSecret(string $key): void
    {
        $lower = mb_strtolower($key);

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                throw new \RuntimeException(
                    'Secrets belong in .env, not in the settings table: ' . $key
                );
            }
        }
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int'     => (int) $value,
            'decimal' => number_format((float) $value, 2, '.', ''),
            'bool'    => in_array($value, ['1', 'true', 'yes', 'on'], true),
            'json'    => json_decode($value, true) ?? [],
            default   => $value,
        };
    }
}
