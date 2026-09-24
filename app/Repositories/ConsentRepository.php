<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Enums\ConsentType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;

/**
 * Consent, and the per-category channel preferences that sit on top of it.
 *
 * `consent_records` is append-only. Withdrawal is a new row with granted = 0,
 * never an update, because proving what somebody agreed to and when needs the
 * whole trail rather than a single mutable flag.
 *
 * The current state is therefore always "the newest row", which is what
 * currentlyGrants() reads. Getting that query wrong is how a withdrawn consent
 * quietly starts granting again, so it is asserted directly by the tests.
 */
final class ConsentRepository extends Repository
{
    protected string $table = 'consent_records';

    /**
     * Records a grant or a withdrawal. Never updates; always inserts.
     */
    public function record(
        int $userId,
        ConsentType $type,
        bool $granted,
        string $source,
        string $ip = '',
        ?int $actorUserId = null,
        string $version = 'v1.0'
    ): int {
        $this->statement(
            'INSERT INTO consent_records
                (user_id, consent_type, granted, version, source, ip_address, actor_user_id, created_at)
             VALUES
                (:user_id, :type, :granted, :version, :source,
                 CASE WHEN :ip IS NULL THEN NULL ELSE INET6_ATON(:ip2) END,
                 :actor, UTC_TIMESTAMP())',
            [
                'user_id' => $userId,
                'type'    => $type->value,
                'granted' => $granted ? 1 : 0,
                'version' => $version,
                'source'  => mb_substr($source, 0, 60),
                'ip'      => $ip === '' ? null : $ip,
                'ip2'     => $ip === '' ? null : $ip,
                'actor'   => $actorUserId,
            ]
        );

        return $this->lastId();
    }

    /**
     * The current consent record, for a screen that has to SHOW what was
     * agreed rather than assert it.
     *
     * "You consented" with no date, source or version is not a record, it is a
     * claim. Returns nulls rather than an empty array so a template can render
     * the row either way.
     *
     * @return array{granted:bool,at:?string,source:?string,version:?string}
     */
    public function latest(int $userId, ConsentType $type): array
    {
        $row = $this->selectOne(
            'SELECT granted, source, version, created_at
               FROM consent_records
              WHERE user_id = :id AND consent_type = :type
              ORDER BY created_at DESC, id DESC
              LIMIT 1',
            ['id' => $userId, 'type' => $type->value]
        );

        if ($row === null) {
            // No row is a refusal, not an absence of opinion - consent is
            // opt-in. The screen says "not given" rather than going blank.
            return ['granted' => false, 'at' => null, 'source' => null, 'version' => null];
        }

        return [
            'granted' => (int) $row['granted'] === 1,
            'at'      => (string) $row['created_at'],
            'source'  => (string) $row['source'],
            'version' => (string) $row['version'],
        ];
    }

    /**
     * Whether the user currently consents to this.
     *
     * The latest row wins. No row at all means no - consent is opt-in, so
     * silence is a refusal, not a default yes. The seed contains a customer
     * with no row for exactly this reason.
     */
    public function currentlyGrants(int $userId, ConsentType $type): bool
    {
        $granted = $this->scalar(
            'SELECT granted
               FROM consent_records
              WHERE user_id = :id AND consent_type = :type
              ORDER BY created_at DESC, id DESC
              LIMIT 1',
            ['id' => $userId, 'type' => $type->value]
        );

        return $granted !== null && (int) $granted === 1;
    }

    /**
     * The full history, newest first - what a subject access request needs, and
     * what support looks at when a customer says they never signed up.
     *
     * @return list<array<string,mixed>>
     */
    public function historyFor(int $userId, ?ConsentType $type = null): array
    {
        $where    = 'user_id = :id';
        $bindings = ['id' => $userId];

        if ($type !== null) {
            $where             .= ' AND consent_type = :type';
            $bindings['type']   = $type->value;
        }

        return $this->select(
            "SELECT consent_type, granted, version, source, actor_user_id, created_at,
                    INET6_NTOA(ip_address) AS ip
               FROM consent_records
              WHERE {$where}
              ORDER BY created_at DESC, id DESC",
            $bindings
        );
    }

    // ---- Channel preferences ------------------------------------------------

    /**
     * Whether a specific channel is switched on for a category.
     *
     * Two separate gates, and both must pass: consent says "you may send me
     * marketing at all", the preference says "but not by SMS". Collapsing them
     * into one flag loses the difference between "no thanks" and "not this way".
     */
    public function channelEnabled(int $userId, NotificationCategory $category, NotificationChannel $channel): bool
    {
        $enabled = $this->scalar(
            'SELECT is_enabled FROM notification_preferences
              WHERE user_id = :id AND category = :category AND channel = :channel',
            ['id' => $userId, 'category' => $category->value, 'channel' => $channel->value]
        );

        // No row means the default, which is on for transactional categories
        // and off for offers. A customer who has never touched the settings
        // still gets told their order is ready.
        if ($enabled === null) {
            return $category !== NotificationCategory::Offers;
        }

        return (int) $enabled === 1;
    }

    public function setChannel(
        int $userId,
        NotificationCategory $category,
        NotificationChannel $channel,
        bool $enabled
    ): void {
        $this->statement(
            'INSERT INTO notification_preferences (user_id, category, channel, is_enabled, updated_at)
             VALUES (:user_id, :category, :channel, :enabled, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = UTC_TIMESTAMP()',
            [
                'user_id'  => $userId,
                'category' => $category->value,
                'channel'  => $channel->value,
                'enabled'  => $enabled ? 1 : 0,
            ]
        );
    }

    /**
     * Every preference for a user, as category => channel => bool, filled in
     * with the defaults so a settings screen never has to guess.
     *
     * @return array<string,array<string,bool>>
     */
    public function preferencesFor(int $userId): array
    {
        $rows = $this->select(
            'SELECT category, channel, is_enabled FROM notification_preferences WHERE user_id = :id',
            ['id' => $userId]
        );

        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) $row['category']][(string) $row['channel']] = (int) $row['is_enabled'] === 1;
        }

        $out = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (NotificationChannel::cases() as $channel) {
                $out[$category->value][$channel->value] =
                    $stored[$category->value][$channel->value]
                    ?? ($category !== NotificationCategory::Offers);
            }
        }

        return $out;
    }

    /**
     * Switches everything off in one go - what an unsubscribe link does.
     * Marketing consent is withdrawn as well, because somebody who clicks
     * unsubscribe has said no to the thing, not just to one channel.
     */
    public function unsubscribeAll(int $userId, string $source = 'unsubscribe_link'): void
    {
        $this->statement(
            "UPDATE notification_preferences
                SET is_enabled = 0, updated_at = UTC_TIMESTAMP()
              WHERE user_id = :id AND category IN ('offers', 'reorder')",
            ['id' => $userId]
        );

        // Make sure a row exists to be switched off, for a user who never
        // visited the settings screen.
        foreach ([NotificationCategory::Offers, NotificationCategory::Reorder] as $category) {
            foreach (NotificationChannel::cases() as $channel) {
                $this->setChannel($userId, $category, $channel, false);
            }
        }

        $this->record($userId, ConsentType::Marketing, false, $source);
    }

    private function lastId(): int
    {
        return (int) $this->scalar('SELECT LAST_INSERT_ID()');
    }
}
