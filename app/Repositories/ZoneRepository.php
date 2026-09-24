<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Delivery zones: which areas we cover, what it costs, and who covers them.
 *
 * The zone is what makes a delivery fee a fact rather than a guess. An address
 * that matches no zone has no fee, and the checkout says so instead of
 * inventing one - a delivery promised into an area with no agents is worse than
 * no delivery offer at all.
 */
final class ZoneRepository extends Repository
{
    protected string $table = 'delivery_zones';

    /**
     * The zone covering an address.
     *
     * A stored zone_id wins when it is still active, because it was resolved
     * when the address was saved and may have been corrected by hand. Otherwise
     * the region/district pair is matched, case-insensitively - addresses are
     * typed by people.
     *
     * @return array<string,mixed>|null
     */
    public function forAddress(string $region, string $district, ?int $zoneId = null): ?array
    {
        if ($zoneId !== null) {
            $zone = $this->selectOne(
                'SELECT * FROM delivery_zones WHERE id = :id AND is_active = 1',
                ['id' => $zoneId]
            );

            if ($zone !== null) {
                return $zone;
            }
        }

        return $this->selectOne(
            'SELECT z.*
               FROM delivery_zones z
               JOIN zone_districts d ON d.zone_id = z.id
              WHERE z.is_active = 1
                AND LOWER(d.region) = LOWER(:region)
                AND LOWER(d.district) = LOWER(:district)
              LIMIT 1',
            ['region' => trim($region), 'district' => trim($district)]
        );
    }

    public function coversDistrict(string $region, string $district): bool
    {
        return $this->forAddress($region, $district) !== null;
    }

    /** @return list<array<string,mixed>> */
    public function activeZones(): array
    {
        return $this->select(
            'SELECT z.*,
                    (SELECT COUNT(*) FROM zone_districts d WHERE d.zone_id = z.id) AS district_count,
                    (SELECT COUNT(*) FROM agent_zones a WHERE a.zone_id = z.id) AS agent_count
               FROM delivery_zones z
              WHERE z.is_active = 1
              ORDER BY z.region, z.name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function districtsFor(int $zoneId): array
    {
        return $this->select(
            'SELECT region, district FROM zone_districts WHERE zone_id = :id ORDER BY region, district',
            ['id' => $zoneId]
        );
    }

    /**
     * Agents who cover a zone and are currently marked available.
     *
     * Ordered by how little they already have on, so work spreads rather than
     * landing on whoever happens to sort first.
     *
     * @return list<array<string,mixed>>
     */
    public function availableAgentsIn(int $zoneId): array
    {
        return $this->select(
            "SELECT p.user_id, p.vehicle_type, p.max_weight_grams, p.deliveries_completed, p.deliveries_failed,
                    CONCAT(u.first_name, ' ', u.last_name) AS name, u.phone,
                    (SELECT COUNT(*) FROM delivery_tasks t
                      WHERE t.agent_user_id = p.user_id
                        AND t.status IN ('assigned', 'picked_up', 'out_for_delivery')
                    ) AS open_tasks
               FROM delivery_agent_profiles p
               JOIN agent_zones az ON az.user_id = p.user_id
               JOIN users u        ON u.id = p.user_id
              WHERE az.zone_id = :zone
                AND p.is_available = 1
                AND u.status = 'active'
              ORDER BY open_tasks, p.deliveries_failed",
            ['zone' => $zoneId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function zonesForAgent(int $agentUserId): array
    {
        return $this->select(
            'SELECT z.id, z.name, z.region, z.assignment_mode
               FROM delivery_zones z
               JOIN agent_zones a ON a.zone_id = z.id
              WHERE a.user_id = :agent AND z.is_active = 1
              ORDER BY z.name',
            ['agent' => $agentUserId]
        );
    }

    public function agentCoversZone(int $agentUserId, int $zoneId): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM agent_zones WHERE user_id = :agent AND zone_id = :zone',
            ['agent' => $agentUserId, 'zone' => $zoneId]
        ) > 0;
    }

    /** @param array<string,mixed> $data */
    public function createZone(array $data): int
    {
        return $this->insertInto('delivery_zones', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateZone(int $zoneId, array $data): int
    {
        return $this->updateWhere('delivery_zones', $data, 'id = :id', ['id' => $zoneId]);
    }

    public function addDistrict(int $zoneId, string $region, string $district): int
    {
        return $this->insertInto('zone_districts', [
            'zone_id'  => $zoneId,
            'region'   => trim($region),
            'district' => trim($district),
        ]);
    }
}
