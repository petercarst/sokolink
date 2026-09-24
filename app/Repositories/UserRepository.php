<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    protected string $table = 'users';

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->selectOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => mb_strtolower(trim($email))]
        );
    }

    public function emailExists(string $email): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM users WHERE email = :email',
            ['email' => mb_strtolower(trim($email))]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $data['email'] = mb_strtolower(trim((string) $data['email']));

        return $this->insertInto('users', $data);
    }

    /**
     * Role keys held by a user. The source of truth is the junction table, not
     * a column on `users`: one account can be both a customer and a seller.
     *
     * @return list<string>
     */
    public function roleKeys(int $userId): array
    {
        $rows = $this->select(
            'SELECT r.role_key
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :id
              ORDER BY r.id',
            ['id' => $userId]
        );

        return array_map(static fn (array $r): string => (string) $r['role_key'], $rows);
    }

    /**
     * Every permission the user's roles grant, flattened and de-duplicated.
     *
     * Authorisation checks this, never a role name. Changing what a role can do
     * is then data, not a deployment - and there is exactly one place a
     * permission can come from.
     *
     * @return list<string>
     */
    public function permissionKeys(int $userId): array
    {
        $rows = $this->select(
            'SELECT DISTINCT p.permission_key
               FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE ur.user_id = :id
              ORDER BY p.permission_key',
            ['id' => $userId]
        );

        return array_map(static fn (array $r): string => (string) $r['permission_key'], $rows);
    }

    public function assignRole(int $userId, string $roleKey, ?int $grantedBy = null): void
    {
        $this->statement(
            'INSERT IGNORE INTO user_roles (user_id, role_id, granted_at, granted_by)
             SELECT :user_id, r.id, UTC_TIMESTAMP(), :granted_by
               FROM roles r
              WHERE r.role_key = :role_key',
            ['user_id' => $userId, 'granted_by' => $grantedBy, 'role_key' => $roleKey]
        );
    }

    public function removeRole(int $userId, string $roleKey): int
    {
        return $this->statement(
            'DELETE ur FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :user_id AND r.role_key = :role_key',
            ['user_id' => $userId, 'role_key' => $roleKey]
        );
    }

    public function hasRole(int $userId, string $roleKey): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = :user_id AND r.role_key = :role_key',
            ['user_id' => $userId, 'role_key' => $roleKey]
        ) > 0;
    }

    // ---- Scoping keys ------------------------------------------------------

    /**
     * The `sellers.id` for a seller account. This is the value every
     * seller-scoped query filters on, and it is derived from the session user
     * rather than accepted from the request - which is the whole point.
     */
    public function sellerIdFor(int $userId): ?int
    {
        $id = $this->scalar('SELECT id FROM sellers WHERE user_id = :id', ['id' => $userId]);

        return $id === null ? null : (int) $id;
    }

    public function isDeliveryAgent(int $userId): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM delivery_agent_profiles WHERE user_id = :id',
            ['id' => $userId]
        ) > 0;
    }

    // ---- Mutations ---------------------------------------------------------

    public function recordLogin(int $userId, string $ip): void
    {
        $this->statement(
            'UPDATE users
                SET last_login_at = UTC_TIMESTAMP(),
                    last_login_ip = CASE WHEN :ip IS NULL THEN NULL ELSE INET6_ATON(:ip2) END
              WHERE id = :id',
            ['ip' => $ip === '' ? null : $ip, 'ip2' => $ip === '' ? null : $ip, 'id' => $userId]
        );
    }

    public function markEmailVerified(int $userId): int
    {
        // Only lifts the status when it was waiting on this specific step.
        // A suspended account that happens to click a verification link stays
        // suspended.
        return $this->statement(
            "UPDATE users
                SET email_verified_at = UTC_TIMESTAMP(),
                    status = CASE WHEN status = 'pending_verification' THEN 'active' ELSE status END
              WHERE id = :id AND email_verified_at IS NULL",
            ['id' => $userId]
        );
    }

    public function updatePassword(int $userId, string $passwordHash): int
    {
        return $this->updateWhere('users', ['password_hash' => $passwordHash], 'id = :id', ['id' => $userId]);
    }

    public function setStatus(int $userId, string $status, ?string $reason = null): int
    {
        return $this->updateWhere(
            'users',
            ['status' => $status, 'status_reason' => $reason],
            'id = :id',
            ['id' => $userId]
        );
    }

    /** @param array<string,mixed> $data */
    public function updateProfile(int $userId, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone', 'locale']));

        if ($allowed === []) {
            return 0;
        }

        return $this->updateWhere('users', $allowed, 'id = :id', ['id' => $userId]);
    }

    /**
     * Account closure is not a row deletion. The financial record must survive,
     * which the RESTRICT foreign key on orders.user_id enforces anyway - this
     * anonymises what is left.
     */
    public function closeAccount(int $userId): void
    {
        $this->statement(
            "UPDATE users
                SET status        = 'closed',
                    status_reason = 'Closed at the account holder''s request',
                    first_name    = 'Former',
                    last_name     = 'customer',
                    phone         = NULL,
                    email         = CONCAT('closed+', id, '@removed.invalid')
              WHERE id = :id",
            ['id' => $userId]
        );
    }

    // ---- Administration listing --------------------------------------------

    /**
     * @param  array{role?:string,status?:string,q?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['role'])) {
            $where[]          = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                          WHERE ur.user_id = u.id AND r.role_key = :role)';
            $bindings['role'] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[]            = 'u.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['q'])) {
            $where[]       = "(u.email LIKE :q OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q2)";
            $bindings['q']  = '%' . $filters['q'] . '%';
            $bindings['q2'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $bindings);

        $rows = $this->select(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.created_at, u.last_login_at,
                    (SELECT GROUP_CONCAT(r.role_key ORDER BY r.id)
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id) AS roles
               FROM users u
              WHERE {$whereSql}
              ORDER BY u.created_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }
}
