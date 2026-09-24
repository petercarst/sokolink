<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\ReminderBasis;
use App\Domain\Enums\NotificationSkipReason;
use App\Domain\Enums\SkipReason;
use App\Repositories\ConsentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReminderRepository;
use App\Repositories\SettingsRepository;

/**
 * The reorder reminder engine.
 *
 * Two jobs, run by two scheduled tasks:
 *
 *   schedule()  looks at what people have actually received and works out when
 *               they might need it again. Writes reminders.
 *   dispatch()  takes the reminders whose date has arrived and decides, one
 *               suppression rule at a time, whether to actually send them.
 *
 * Neither needs a browser. That is the brief's requirement - "do not rely on a
 * browser being open to execute scheduled reminders" - and it is why both are
 * plain methods with no session, called from bin/console.php.
 *
 * **Every suppression writes a reason.** There are seven of them and each one
 * produces a `skip_reason` on the row. An engine that quietly sends nothing and
 * an engine that is working correctly look identical without that column.
 */
final class ReminderService
{
    public function __construct(
        private readonly ReminderRepository $reminders = new ReminderRepository(),
        private readonly ConsentRepository $consent = new ConsentRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly ConsumptionEstimator $estimator = new ConsumptionEstimator(),
    ) {
    }

    /**
     * Looks at fulfilled purchases and schedules what can be scheduled.
     *
     * Running it twice changes nothing: the cycle key is unique per
     * (customer, product, sub-order), and the second insert is refused by the
     * database.
     *
     * @return array{considered:int,scheduled:int,not_scheduled:int,by_basis:array<string,int>}
     */
    public function schedule(int $limit = 200): array
    {
        $candidates = $this->reminders->candidates($limit);

        $scheduled    = 0;
        $notScheduled = 0;
        $byBasis      = [];

        foreach ($candidates as $candidate) {
            $userId    = (int) $candidate['user_id'];
            $productId = (int) $candidate['product_id'];
            $cycleKey  = $candidate['seller_order_id'] . ':' . $productId;

            $estimate = $this->estimator->estimate(
                $userId,
                $productId,
                (int) $candidate['qty'],
                (string) $candidate['placed_at']
            );

            $byBasis[$estimate['basis']->value] = ($byBasis[$estimate['basis']->value] ?? 0) + 1;

            $id = $this->reminders->schedule(
                $userId,
                $productId,
                $cycleKey,
                $estimate['basis'],
                $estimate['detail'],
                (string) $candidate['placed_at'],
                $estimate['next_due_at'],
                (int) $candidate['seller_order_id']
            );

            if ($id === null) {
                // Already scheduled by an earlier run. Not an error.
                continue;
            }

            if ($estimate['next_due_at'] === null) {
                $notScheduled++;
                continue;
            }

            $scheduled++;
        }

        return [
            'considered'    => count($candidates),
            'scheduled'     => $scheduled,
            'not_scheduled' => $notScheduled,
            'by_basis'      => $byBasis,
        ];
    }

    /**
     * Sends the reminders that are due - or records why each one is not.
     *
     * @return array{due:int,sent:int,skipped:int,by_reason:array<string,int>}
     */
    public function dispatch(int $limit = 100): array
    {
        $due       = $this->reminders->dueNow($limit);
        $sent      = 0;
        $skipped   = 0;
        $byReason  = [];

        foreach ($due as $reminder) {
            $reason = $this->suppressionFor($reminder);

            if ($reason !== null) {
                $this->reminders->markSkipped((int) $reminder['id'], $reason);

                $this->notifications->queueSkipped(
                    (int) $reminder['user_id'],
                    NotificationChannel::Email,
                    NotificationCategory::Reorder,
                    'reorder.reminder',
                    NotificationSkipReason::fromReminderSkip($reason),
                    ['product' => (string) $reminder['product_name']],
                    'reorder_reminder',
                    (int) $reminder['id']
                );

                $byReason[$reason->value] = ($byReason[$reason->value] ?? 0) + 1;
                $skipped++;

                continue;
            }

            $notificationId = $this->notifications->queue(
                (int) $reminder['user_id'],
                NotificationChannel::Email,
                NotificationCategory::Reorder,
                'reorder.reminder',
                [
                    'first_name'   => (string) $reminder['first_name'],
                    'product'      => (string) $reminder['product_name'],
                    'product_slug' => (string) $reminder['product_slug'],
                    'basis'        => (string) $reminder['basis'],
                    'last_bought'  => (string) $reminder['last_purchased_at'],
                ],
                // Marketing, so the frequency cap and the consent rules apply.
                true,
                'reorder_reminder',
                (int) $reminder['id']
            );

            $this->reminders->markSent((int) $reminder['id'], $notificationId);
            $sent++;
        }

        return ['due' => count($due), 'sent' => $sent, 'skipped' => $skipped, 'by_reason' => $byReason];
    }

    /**
     * Should this reminder be suppressed, and why?
     *
     * Ordered from "must never send" to "probably unhelpful". The first match
     * wins and is the recorded reason, so a customer who both withdrew consent
     * and already repurchased is recorded as consent_withdrawn - the stronger
     * fact.
     *
     * @param array<string,mixed> $reminder
     */
    public function suppressionFor(array $reminder): ?SkipReason
    {
        $userId    = (int) $reminder['user_id'];
        $productId = (int) $reminder['product_id'];

        // 1. Consent. The whole feature is opt-in; no row means no.
        if (!$this->consent->currentlyGrants($userId, ConsentType::Marketing)) {
            return $this->everConsented($userId)
                ? SkipReason::ConsentWithdrawn
                : SkipReason::NoConsent;
        }

        // 2. The channel switch. Consent says "you may"; the preference says
        //    "but not this way".
        if (!$this->consent->channelEnabled($userId, NotificationCategory::Reorder, NotificationChannel::Email)) {
            return SkipReason::NoConsent;
        }

        // 3. They already bought it again. Nothing to remind them about.
        if ($this->reminders->repurchasedSince($userId, $productId, (string) $reminder['last_purchased_at'])) {
            return SkipReason::AlreadyRepurchased;
        }

        // 4. The product is gone. Sending somebody to a dead page is worse than
        //    saying nothing.
        if ((string) $reminder['product_status'] !== 'published') {
            return SkipReason::Unavailable;
        }

        if (!$this->isPurchasable($productId)) {
            return SkipReason::Unavailable;
        }

        // 5. Cooldown - too soon after the last reminder of any kind.
        $cooldownDays = $this->settings->getInt('reminders.cooldown_days', 14);
        $lastSent     = $this->reminders->lastSentAt($userId);

        if ($lastSent !== null && strtotime($lastSent . ' UTC') > time() - ($cooldownDays * 86400)) {
            return SkipReason::Cooldown;
        }

        // 6. Monthly cap. However many products are due, there is a limit to
        //    how often we are willing to be in somebody's inbox.
        $cap = $this->settings->getInt('reminders.monthly_cap', 4);

        if ($this->reminders->sentInLastDays($userId, 30) >= $cap) {
            return SkipReason::FrequencyCap;
        }

        // 7. Quiet hours, in the CUSTOMER's local time rather than the
        //    server's. A reminder at 03:00 is not a reminder, it is a
        //    disturbance.
        if ($this->isQuietHourFor($userId)) {
            return SkipReason::QuietHours;
        }

        return null;
    }

    // ---- Customer actions ---------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function forCustomer(int $userId): array
    {
        return $this->reminders->forUser($userId);
    }

    public function snooze(int $reminderId, int $userId, int $days): void
    {
        $owned = (int) Database::scalar(
            'SELECT COUNT(*) FROM reorder_reminders WHERE id = :id AND user_id = :user',
            ['id' => $reminderId, 'user' => $userId]
        );

        if ($owned === 0) {
            throw new DomainRuleException('That reminder could not be found.', 'not_found');
        }

        $this->reminders->snooze($reminderId, $days);

        Audit::record('reminder.snoozed', 'reorder_reminder', $reminderId, sprintf('Snoozed %d days', $days));
    }

    public function dismiss(int $reminderId, int $userId): void
    {
        if ($this->reminders->close($reminderId, $userId) === 0) {
            throw new DomainRuleException('That reminder could not be found.', 'not_found');
        }

        Audit::record('reminder.dismissed', 'reorder_reminder', $reminderId, 'Closed by the customer');
    }

    /**
     * Records that a reminder led to an order - the only measure of whether
     * any of this is worth doing.
     */
    public function recordConversion(int $userId, int $productId, int $orderId): void
    {
        $reminderId = Database::scalar(
            "SELECT id FROM reorder_reminders
              WHERE user_id = :user AND product_id = :product AND state = 'sent'
              ORDER BY updated_at DESC LIMIT 1",
            ['user' => $userId, 'product' => $productId]
        );

        if ($reminderId !== null) {
            $this->reminders->markConverted((int) $reminderId, $orderId);
        }
    }

    /**
     * @return array{by_state:array<string,int>,by_basis:array<string,int>,by_skip:array<string,int>}
     */
    public function statistics(int $days = 30): array
    {
        return $this->reminders->statistics($days);
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * Distinguishes "never agreed" from "agreed then changed their mind".
     *
     * Both mean do not send. They are recorded differently because they are
     * different facts, and the difference matters if anyone ever asks what a
     * customer was told and when.
     */
    private function everConsented(int $userId): bool
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM consent_records
              WHERE user_id = :user AND consent_type = 'marketing' AND granted = 1",
            ['user' => $userId]
        ) > 0;
    }

    /** Is the product actually buyable right now, anywhere? */
    private function isPurchasable(int $productId): bool
    {
        return (int) Database::scalar(
            "SELECT COALESCE(SUM(i.qty_available), 0)
               FROM inventory i
               JOIN stores s ON s.id = i.store_id
              WHERE i.product_id = :id AND s.status = 'published'",
            ['id' => $productId]
        ) > 0;
    }

    /**
     * Quiet hours in the display timezone.
     *
     * Everything is stored in UTC, so the check converts. Comparing a UTC hour
     * against a local quiet-hours setting is how a system ends up messaging
     * everybody at three in the morning and nobody noticing for a week.
     */
    private function isQuietHourFor(int $userId): bool
    {
        $start = $this->settings->getInt('notify.quiet_hours_start', (int) Config::get('notify.quiet_start', 21));
        $end   = $this->settings->getInt('notify.quiet_hours_end', (int) Config::get('notify.quiet_end', 7));

        $localHour = (int) Clock::local(Clock::nowUtc())->format('G');

        // The window wraps midnight, so it is not a simple between.
        return $start > $end
            ? ($localHour >= $start || $localHour < $end)
            : ($localHour >= $start && $localHour < $end);
    }
}
