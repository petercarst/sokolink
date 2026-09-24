<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Writes to the append-only audit trail (D-08).
 *
 * There is no update() and no delete() here, and there is none anywhere else
 * either - not for support, not for administrators. An audit log that
 * privileged users can rewrite is not evidence of anything.
 *
 * The actor is read from the session, never from the request. A caller cannot
 * claim to be somebody else by posting a field.
 */
final class Audit
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $detail = null,
        ?array $before = null,
        ?array $after = null,
        ?string $justification = null
    ): int {
        $actorId   = Auth::id();
        $actorRole = Auth::primaryRole();

        // INET6_ATON rather than binding raw inet_pton() bytes: the packed
        // form contains NUL bytes, and letting the server do the conversion
        // keeps that out of the driver entirely.
        Database::statement(
            'INSERT INTO audit_log
                (actor_user_id, actor_role, action, entity_type, entity_id, detail,
                 before_json, after_json, justification, ip_address, user_agent, created_at)
             VALUES
                (:actor_user_id, :actor_role, :action, :entity_type, :entity_id, :detail,
                 :before_json, :after_json, :justification,
                 CASE WHEN :ip IS NULL THEN NULL ELSE INET6_ATON(:ip2) END,
                 :user_agent, UTC_TIMESTAMP())',
            [
                'actor_user_id' => $actorId,
                'actor_role'    => $actorRole,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId !== null ? (string) $entityId : null,
                'detail'        => $detail !== null ? mb_substr($detail, 0, 1000) : null,
                'before_json'   => $before !== null ? self::encode(self::redact($before)) : null,
                'after_json'    => $after !== null ? self::encode(self::redact($after)) : null,
                'justification' => $justification !== null ? mb_substr($justification, 0, 120) : null,
                'ip'            => self::ipAddress(),
                'ip2'           => self::ipAddress(),
                'user_agent'    => mb_substr(self::userAgent(), 0, 255),
            ]
        );

        return Database::lastInsertId();
    }

    /**
     * Convenience for the common "this field changed" case.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function changed(
        string $action,
        string $entityType,
        int|string $entityId,
        array $before,
        array $after,
        ?string $justification = null
    ): int {
        // Only the keys that actually differ. A log full of unchanged fields
        // is a log nobody reads.
        $changedKeys = [];
        foreach ($after as $key => $value) {
            if (!array_key_exists($key, $before) || $before[$key] !== $value) {
                $changedKeys[] = $key;
            }
        }

        return self::record(
            $action,
            $entityType,
            $entityId,
            $changedKeys === [] ? 'No field changed' : 'Changed: ' . implode(', ', $changedKeys),
            array_intersect_key($before, array_flip($changedKeys)),
            array_intersect_key($after, array_flip($changedKeys)),
            $justification
        );
    }

    /**
     * Records an action taken against a customer by staff. The justification is
     * mandatory here: "why did support look at this?" must have an answer
     * (FR-SUP-05).
     */
    public static function sensitive(
        string $action,
        string $entityType,
        int|string $entityId,
        string $justification,
        ?string $detail = null
    ): int {
        return self::record($action, $entityType, $entityId, $detail, null, null, $justification);
    }

    /**
     * Fields that must never appear in the trail even if a caller passes a
     * whole row. Hashes are not secrets, but they are also not useful in a log,
     * and a log is copied around far more casually than a database.
     *
     * @param  array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function redact(array $data): array
    {
        $blocked = [
            'password', 'password_hash', 'password_confirmation',
            'token', 'token_hash', 'selector',
            'code', 'code_hash', 'collection_code', 'delivery_code',
            'secret', 'webhook_secret', 'api_key',
        ];

        foreach ($data as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $blocked, true)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? mb_substr($json, 0, 60000) : '{}';
    }

    private static function ipAddress(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $ip = Request::current()->ip();

        return ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) ? null : $ip;
    }

    private static function userAgent(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : Request::current()->userAgent();
    }
}
