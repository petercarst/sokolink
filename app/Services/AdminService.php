<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\SellerRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SupportRepository;
use App\Repositories\UserRepository;

/**
 * Administration: approvals, moderation, settings, oversight.
 *
 * An administrator can do more than anyone else, which is exactly why every
 * action here is audited with before/after values. The privilege that needs
 * the least explaining is usually the one that needs the most record.
 *
 * Two things an administrator still cannot do, and they are enforced rather
 * than discouraged:
 *
 *   - Read a collection or delivery code. Nothing can; only hashes are stored.
 *   - Edit an audit entry, a status history row or a stock movement. Those
 *     tables have no update path in any layer.
 */
final class AdminService
{
    public function __construct(
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ReviewRepository $reviews = new ReviewRepository(),
        private readonly SupportRepository $support = new SupportRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * Approving a seller application.
     *
     * This is the moment an applicant becomes able to trade, so it does four
     * things in one transaction: records the decision, creates the `sellers`
     * row, activates the user account and tells them.
     *
     * The decision is guarded on the application still being pending, so two
     * administrators opening the same one cannot both approve it.
     */
    public function approveSellerApplication(int $applicationId): int
    {
        $application = $this->sellers->findApplication($applicationId);

        if ($application === null) {
            throw new DomainRuleException('That application could not be found.', 'not_found');
        }

        if ((string) $application['status'] !== 'pending_approval') {
            throw new DomainRuleException(
                'That application has already been decided.',
                'already_decided'
            );
        }

        return Database::transaction(function () use ($application, $applicationId): int {
            $decided = $this->sellers->decideApplication(
                $applicationId,
                'approved',
                (int) Auth::id(),
                null
            );

            if ($decided !== 1) {
                throw new DomainRuleException(
                    'Another administrator decided that application a moment ago.',
                    'already_decided'
                );
            }

            $sellerId = $this->sellers->create([
                'user_id'             => (int) $application['user_id'],
                'slug'                => $this->uniqueSellerSlug((string) $application['business_name']),
                'business_name'       => (string) $application['business_name'],
                'registration_number' => $application['registration_number'],
                'contact_email'       => (string) $application['email'],
                'contact_phone'       => (string) $application['contact_phone'],
                'status'              => 'active',
                'commission_percent'  => $this->settings->getDecimal('commission.default_percent', '5.00'),
                'approved_at'         => gmdate('Y-m-d H:i:s'),
            ]);

            // The account can now be used normally. Until this point it was
            // pending_approval, which let them sign in and watch but not trade.
            $this->users->setStatus((int) $application['user_id'], 'active', null);

            $this->notifications->queue(
                (int) $application['user_id'],
                NotificationChannel::Email,
                NotificationCategory::OrderUpdates,
                'seller.application_approved',
                ['business_name' => (string) $application['business_name']],
                false,
                'seller_application',
                $applicationId
            );

            Audit::changed(
                'seller.application.approved',
                'seller_application',
                $applicationId,
                ['status' => 'pending_approval'],
                ['status' => 'approved', 'seller_id' => $sellerId]
            );

            return $sellerId;
        });
    }

    /**
     * Rejecting an application.
     *
     * The reason is mandatory and it goes to the applicant. Telling somebody
     * their business has been refused without saying why is not acceptable, and
     * making the reason optional guarantees it will sometimes be missing.
     */
    public function rejectSellerApplication(int $applicationId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainRuleException(
                'Explain the decision in a sentence the applicant can act on.',
                'reason_required'
            );
        }

        $application = $this->sellers->findApplication($applicationId);

        if ($application === null) {
            throw new DomainRuleException('That application could not be found.', 'not_found');
        }

        Database::transaction(function () use ($application, $applicationId, $reason): void {
            $decided = $this->sellers->decideApplication(
                $applicationId,
                'rejected',
                (int) Auth::id(),
                $reason
            );

            if ($decided !== 1) {
                throw new DomainRuleException('That application has already been decided.', 'already_decided');
            }

            // The account stays usable as a customer account. Refusing a
            // business application is not a reason to lock somebody out of
            // orders they have already placed.
            $this->users->removeRole((int) $application['user_id'], 'seller');
            $this->users->setStatus((int) $application['user_id'], 'active', null);
            $this->users->assignRole((int) $application['user_id'], 'customer', (int) Auth::id());

            $this->notifications->queue(
                (int) $application['user_id'],
                NotificationChannel::Email,
                NotificationCategory::OrderUpdates,
                'seller.application_rejected',
                ['reason' => $reason],
                false,
                'seller_application',
                $applicationId
            );

            Audit::changed(
                'seller.application.rejected',
                'seller_application',
                $applicationId,
                ['status' => 'pending_approval'],
                ['status' => 'rejected'],
                $reason
            );
        });
    }

    /**
     * Suspending an account.
     *
     * Takes effect on the very next request, because Auth reads status from the
     * database rather than trusting the session. The reason is mandatory and
     * shown to the account holder.
     */
    public function suspendUser(int $userId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainRuleException('Record why this account is being suspended.', 'reason_required');
        }

        if ($userId === Auth::id()) {
            throw new DomainRuleException('You cannot suspend your own account.', 'self_suspend');
        }

        $before = $this->users->find($userId);

        if ($before === null) {
            throw new DomainRuleException('That account could not be found.', 'not_found');
        }

        $this->users->setStatus($userId, 'suspended', $reason);

        Audit::changed(
            'user.suspended',
            'user',
            $userId,
            ['status' => $before['status']],
            ['status' => 'suspended'],
            $reason
        );
    }

    public function reinstateUser(int $userId, string $reason): void
    {
        $before = $this->users->find($userId);

        if ($before === null) {
            throw new DomainRuleException('That account could not be found.', 'not_found');
        }

        if ((string) $before['status'] !== 'suspended') {
            throw new DomainRuleException('That account is not suspended.', 'not_suspended');
        }

        $this->users->setStatus($userId, 'active', null);

        Audit::changed(
            'user.reinstated',
            'user',
            $userId,
            ['status' => 'suspended'],
            ['status' => 'active'],
            $reason
        );
    }

    /**
     * Suspending a seller stops them trading without touching their orders.
     *
     * Their products disappear from the marketplace immediately - the public
     * queries all require `sellers.status = 'active'` - but orders already in
     * flight still have to be fulfilled, so nothing about them changes.
     */
    public function suspendSeller(int $sellerId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainRuleException('Record why this seller is being suspended.', 'reason_required');
        }

        $this->sellers->update($sellerId, ['status' => 'suspended']);

        Audit::changed(
            'seller.suspended',
            'seller',
            $sellerId,
            ['status' => 'active'],
            ['status' => 'suspended'],
            $reason
        );
    }

    public function moderateReview(int $reviewId, string $status, ?string $reason): void
    {
        if (!in_array($status, ['published', 'rejected', 'hidden'], true)) {
            throw new DomainRuleException('That is not a moderation decision.', 'invalid_status');
        }

        if ($status !== 'published' && mb_strlen(trim((string) $reason)) < 5) {
            throw new DomainRuleException('Say why the review is being removed.', 'reason_required');
        }

        $review = $this->reviews->find($reviewId);

        if ($review === null) {
            throw new DomainRuleException('That review could not be found.', 'not_found');
        }

        Database::transaction(function () use ($review, $reviewId, $status, $reason): void {
            $this->reviews->moderate($reviewId, $status, $reason);

            // The cached averages are recalculated from the published set, so
            // hiding a review actually changes the rating rather than leaving a
            // stale number behind.
            $this->reviews->refreshRating((int) $review['product_id']);

            $sellerId = (int) Database::scalar(
                'SELECT seller_id FROM products WHERE id = :p',
                ['p' => $review['product_id']]
            );
            $this->sellers->refreshRating($sellerId);

            Audit::changed(
                'review.moderated',
                'review',
                $reviewId,
                ['status' => $review['status']],
                ['status' => $status],
                $reason
            );
        });
    }

    /**
     * Changes a platform setting.
     *
     * SettingsRepository refuses keys that look like secrets, so this cannot be
     * used to smuggle a credential into the database where an admin screen
     * could read it back.
     */
    public function updateSetting(string $key, string $value): void
    {
        if (!$this->settings->set($key, $value, (int) Auth::id())) {
            throw new DomainRuleException('There is no setting with that name.', 'unknown_setting');
        }
    }

    /**
     * The overview figures.
     *
     * Revenue counts paid orders only. A dashboard that counts money that never
     * arrived is a dashboard nobody trusts twice.
     *
     * @return array<string,mixed>
     */
    public function dashboard(int $days = 30): array
    {
        return [
            'orders'   => $this->orders->platformTotals($days),
            'sellers'  => [
                'pending_applications' => $this->sellers->pendingApplicationCount(),
                'active'   => (int) Database::scalar("SELECT COUNT(*) FROM sellers WHERE status = 'active'"),
                'suspended' => (int) Database::scalar("SELECT COUNT(*) FROM sellers WHERE status = 'suspended'"),
            ],
            'reviews'  => ['pending' => $this->reviews->pendingCount()],
            'support'  => $this->support->queueCounts(),
            'users'    => [
                'total'     => (int) Database::scalar('SELECT COUNT(*) FROM users'),
                'suspended' => (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
                'new'       => (int) Database::scalar(
                    'SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)',
                    ['days' => $days]
                ),
            ],
            'health'   => $this->health(),
        ];
    }

    /**
     * The things an operator needs to notice.
     *
     * Not a green tick that means nothing: each of these is a count of
     * something that is actually stuck.
     *
     * @return array<string,int>
     */
    public function health(): array
    {
        return [
            'unassigned_deliveries' => (int) Database::scalar(
                "SELECT COUNT(*) FROM delivery_tasks t JOIN seller_orders so ON so.id = t.seller_order_id
                  WHERE t.status = 'unassigned' AND so.status = 'ready_for_dispatch'"
            ),
            'overdue_collections' => (int) Database::scalar(
                "SELECT COUNT(*) FROM seller_orders WHERE status = 'collection_overdue'"
            ),
            'orders_awaiting_seller' => (int) Database::scalar(
                "SELECT COUNT(*) FROM seller_orders WHERE status = 'awaiting_seller'
                    AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            ),
            'failed_notifications' => (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications WHERE status = 'failed'"
            ),
            'flagged_payments' => (int) Database::scalar(
                "SELECT COUNT(*) FROM payment_transactions WHERE status = 'flagged_for_review'"
            ),
            'refunds_pending' => (int) Database::scalar(
                "SELECT COUNT(*) FROM seller_orders WHERE status = 'refund_pending'"
            ),
            'low_stock_lines' => (int) Database::scalar(
                'SELECT COUNT(*) FROM inventory WHERE qty_available <= low_stock_threshold'
            ),
        ];
    }

    /**
     * The audit trail, filtered. Read-only - there is no method here that
     * changes one, and there is none anywhere else either.
     *
     * @param array{action?:string,actor?:int,entity_type?:string,from?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function auditTrail(array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        $where    = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['action'])) {
            $where[]            = 'a.action LIKE :action';
            $bindings['action'] = $filters['action'] . '%';
        }

        if (!empty($filters['actor'])) {
            $where[]           = 'a.actor_user_id = :actor';
            $bindings['actor'] = (int) $filters['actor'];
        }

        if (!empty($filters['entity_type'])) {
            $where[]                 = 'a.entity_type = :entity';
            $bindings['entity']      = $filters['entity_type'];
        }

        if (!empty($filters['from'])) {
            $where[]          = 'a.created_at >= :from';
            $bindings['from'] = $filters['from'];
        }

        $whereSql = implode(' AND ', $where);

        return Database::select(
            "SELECT a.id, a.action, a.actor_role, a.entity_type, a.entity_id, a.detail,
                    a.justification, a.created_at, INET6_NTOA(a.ip_address) AS ip,
                    CONCAT(COALESCE(u.first_name, 'System'), ' ', COALESCE(u.last_name, '')) AS actor_name
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.actor_user_id
              WHERE {$whereSql}
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT {$limit}",
            $bindings
        );
    }

    private function uniqueSellerSlug(string $businessName): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($businessName)) ?? 'seller';
        $base = trim($base, '-');
        $base = $base === '' ? 'seller' : mb_substr($base, 0, 140);

        $slug    = $base;
        $counter = 1;

        while ($this->sellers->slugExists($slug)) {
            $slug = $base . '-' . (++$counter);
        }

        return $slug;
    }
}
