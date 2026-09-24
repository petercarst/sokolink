<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Customer delivery addresses.
 *
 * Every read is scoped by user_id in the SQL, not filtered afterwards, so one
 * customer's address cannot be reached by changing an id in a form post
 * (NFR-SEC-09, the IDOR case).
 *
 * Addresses are archived, never deleted: an order placed last month must keep
 * the address it was delivered to, and a hard delete would either break that
 * foreign key or quietly rewrite history.
 */
final class AddressRepository extends Repository
{
    protected string $table = 'customer_addresses';

    /**
     * The customer's usable addresses, default first.
     *
     * The zone is joined in because checkout has to know the delivery fee
     * before payment, not after (FR-CART-07). A NULL zone here means "we do
     * not deliver there" and the page must say so.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->select(
            'SELECT a.*, z.id AS zone_id_resolved, z.name AS zone_name,
                    z.base_fee, z.free_threshold
               FROM customer_addresses a
               LEFT JOIN delivery_zones z ON z.id = a.zone_id AND z.is_active = 1
              WHERE a.user_id = :user AND a.archived_at IS NULL
              ORDER BY a.is_default DESC, a.label, a.id',
            ['user' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $addressId, int $userId): ?array
    {
        return $this->selectOne(
            'SELECT a.*, z.name AS zone_name, z.base_fee, z.free_threshold
               FROM customer_addresses a
               LEFT JOIN delivery_zones z ON z.id = a.zone_id AND z.is_active = 1
              WHERE a.id = :id AND a.user_id = :user AND a.archived_at IS NULL',
            ['id' => $addressId, 'user' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function defaultFor(int $userId): ?array
    {
        $addresses = $this->forUser($userId);

        return $addresses[0] ?? null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(int $userId, array $data): int
    {
        return $this->insertInto('customer_addresses', [
            'user_id'      => $userId,
            'label'        => $data['label'],
            'recipient'    => $data['recipient'],
            'phone'        => $data['phone'],
            'region'       => $data['region'],
            'district'     => $data['district'],
            'ward'         => $data['ward'] ?? null,
            'street'       => $data['street'],
            'landmark'     => $data['landmark'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'zone_id'      => $data['zone_id'] ?? null,
            'is_default'   => !empty($data['is_default']) ? 1 : 0,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function updateOwned(int $addressId, int $userId, array $data): int
    {
        return $this->updateWhere(
            'customer_addresses',
            $data,
            'id = :id AND user_id = :user AND archived_at IS NULL',
            ['id' => $addressId, 'user' => $userId]
        );
    }

    /** Exactly one default per customer, enforced by clearing the rest first. */
    public function makeDefault(int $addressId, int $userId): void
    {
        $this->statement(
            'UPDATE customer_addresses SET is_default = 0 WHERE user_id = :user',
            ['user' => $userId]
        );

        $this->updateOwned($addressId, $userId, ['is_default' => 1]);
    }

    public function archive(int $addressId, int $userId): int
    {
        return $this->updateOwned($addressId, $userId, ['archived_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function countFor(int $userId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM customer_addresses WHERE user_id = :user AND archived_at IS NULL',
            ['user' => $userId]
        );
    }
}
