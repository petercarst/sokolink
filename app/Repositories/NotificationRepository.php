<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\NotificationStatus;
use App\Domain\Enums\NotificationSkipReason;

/**
 * The outbound message queue.
 *
 * Nothing sends a message directly. Everything queues a row and a scheduled
 * task drains the queue, which is what makes the brief's "do not rely on a
 * browser being open" true for notifications as well as for reminders - and
 * what stops a slow mail server from making checkout time out.
 *
 * A message that is deliberately not sent is recorded as `skipped` with a
 * reason, never silently dropped. "Why did this customer not get their
 * collection code?" has an answer in one query.
 */
final class NotificationRepository extends Repository
{
    protected string $table = 'notifications';

    /**
     * Queues a message. Returns the new id.
     *
     * @param array<string,mixed> $payload values the template needs
     */
    public function queue(
        int $userId,
        NotificationChannel $channel,
        NotificationCategory $category,
        string $templateKey,
        array $payload = [],
        bool $isMarketing = false,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $sendAfterUtc = null
    ): int {
        return $this->insertInto('notifications', [
            'user_id'        => $userId,
            'channel'        => $channel->value,
            'category'       => $category->value,
            'is_marketing'   => $isMarketing ? 1 : 0,
            'template_key'   => $templateKey,
            'payload_json'   => $this->encode($payload),
            'status'         => NotificationStatus::Queued->value,
            'send_after'     => $sendAfterUtc ?? gmdate('Y-m-d H:i:s'),
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);
    }

    /**
     * Records that a message was deliberately not sent.
     *
     * This is a row, not an absence of a row. Without it the retention engine
     * is a black box and "we did not message them because they withdrew
     * consent" is an assertion nobody can check.
     *
     * @param array<string,mixed> $payload
     */
    public function queueSkipped(
        int $userId,
        NotificationChannel $channel,
        NotificationCategory $category,
        string $templateKey,
        NotificationSkipReason $reason,
        array $payload = [],
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        return $this->insertInto('notifications', [
            'user_id'        => $userId,
            'channel'        => $channel->value,
            'category'       => $category->value,
            'is_marketing'   => 0,
            'template_key'   => $templateKey,
            'payload_json'   => $this->encode($payload),
            'status'         => NotificationStatus::Skipped->value,
            'skip_reason'    => $reason->value,
            'send_after'     => gmdate('Y-m-d H:i:s'),
            'sent_at'        => null,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);
    }

    /**
     * The next batch to attempt.
     *
     * Ordered by due time so the oldest goes first, and limited so one run
     * cannot hold the process for an hour. Attempts that have used their
     * allowance are left alone - a permanently bad address should stop being
     * retried rather than filling the log forever.
     *
     * @return list<array<string,mixed>>
     */
    public function dueForSending(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT n.*, u.email, u.phone, u.first_name, u.last_name, u.locale
               FROM notifications n
               JOIN users u ON u.id = n.user_id
              WHERE n.status = 'queued'
                AND n.send_after <= UTC_TIMESTAMP()
                AND n.attempts < n.max_attempts
              ORDER BY n.send_after
              LIMIT {$limit}"
        );
    }

    /**
     * Claims a message for sending.
     *
     * The guard on `status = 'queued'` and the affected-row check mean two
     * concurrent runs of the CLI task cannot both send the same message. The
     * loser gets 0 and moves on.
     */
    public function claim(int $id): bool
    {
        return $this->statement(
            "UPDATE notifications
                SET status = 'sending', attempts = attempts + 1
              WHERE id = :id AND status = 'queued'",
            ['id' => $id]
        ) === 1;
    }

    /**
     * Keys that must not outlive the message they were rendered into.
     *
     * A collection code is worth something precisely because only the customer
     * has it: order_pickups stores its SHA-256 and nothing can read it back.
     * That guarantee is worthless if the plaintext is also sitting in
     * payload_json, where anything that can read the notifications table can
     * find it - a support screen, a report, a database backup. Same for the
     * verification and reset tokens: single-use and time-limited, but a
     * readable one is still an account takeover until it expires.
     *
     * The payload is needed to COMPOSE the message and not afterwards, so it
     * is scrubbed the moment the message is delivered.
     */
    private const TRANSIENT_PAYLOAD_KEYS = ['code', 'token'];

    public function markDelivered(int $id, string $providerResponse = ''): int
    {
        $affected = $this->statement(
            "UPDATE notifications
                SET status = 'delivered', sent_at = UTC_TIMESTAMP(), provider_response = :response
              WHERE id = :id",
            ['response' => mb_substr($providerResponse, 0, 500), 'id' => $id]
        );

        $this->redactPayload($id);

        return $affected;
    }

    /**
     * Replaces the secret-bearing keys of one message's payload with a marker.
     *
     * A marker rather than a delete, so a support agent reading the row can
     * tell the difference between "this message carried a code" and "this
     * message never had one".
     */
    public function redactPayload(int $id): void
    {
        $json = $this->scalar('SELECT payload_json FROM notifications WHERE id = :id', ['id' => $id]);

        if (!is_string($json) || $json === '') {
            return;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return;
        }

        $changed = false;

        foreach (self::TRANSIENT_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '[redacted]') {
                $payload[$key] = '[redacted]';
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $this->statement(
            'UPDATE notifications SET payload_json = :payload WHERE id = :id',
            ['payload' => $this->encode($payload), 'id' => $id]
        );
    }

    /**
     * Records a failure. A message that still has attempts left goes back to
     * `queued` with a delay; one that has used them all stays `failed`.
     */
    public function markFailed(int $id, string $reason, int $retryAfterMinutes = 15): int
    {
        return $this->statement(
            "UPDATE notifications
                SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'queued' END,
                    send_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE),
                    provider_response = :reason
              WHERE id = :id",
            ['minutes' => $retryAfterMinutes, 'reason' => mb_substr($reason, 0, 500), 'id' => $id]
        );
    }

    public function markSkipped(int $id, NotificationSkipReason $reason): int
    {
        return $this->statement(
            "UPDATE notifications
                SET status = 'skipped', skip_reason = :reason
              WHERE id = :id",
            ['reason' => $reason->value, 'id' => $id]
        );
    }

    /**
     * Cancels queued marketing that has not gone out yet.
     *
     * "Takes effect immediately" has to mean the message sitting in the queue
     * as well, otherwise somebody who unsubscribes still gets the next batch
     * and reasonably concludes the button does nothing. Only `queued` rows are
     * touched: anything already sent cannot be unsent, and saying otherwise in
     * the log would be a lie.
     *
     * They become `cancelled` rather than `skipped`. Skipped is what the sender
     * decided about a message it considered; cancelled is what happened to one
     * it never got to.
     */
    public function cancelQueuedMarketing(int $userId): int
    {
        return $this->statement(
            "UPDATE notifications
                SET status = 'cancelled', skip_reason = 'consent_withdrawn'
              WHERE user_id = :id
                AND status = 'queued'
                AND category IN ('offers', 'reorder')",
            ['id' => $userId]
        );
    }

    /**
     * How many marketing messages this user has had in a window. The frequency
     * cap reads this - a customer who has had four reminders this month gets no
     * more, whatever the estimator thinks.
     */
    public function marketingSentSince(int $userId, int $days): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM notifications
              WHERE user_id = :id
                AND is_marketing = 1
                AND status IN ('delivered', 'sending')
                AND COALESCE(sent_at, send_after) >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)",
            ['id' => $userId, 'days' => $days]
        );
    }

    /**
     * The customer's own notification list, newest first.
     *
     * Skipped rows are excluded: they are operational records, not messages the
     * customer was sent, and showing "we decided not to tell you something"
     * would be strange.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return $this->select(
            "SELECT n.id, n.channel, n.category, n.is_marketing, n.template_key, n.payload_json,
                    n.status, n.sent_at, n.read_at, n.reference_type, n.reference_id, n.created_at,
                    -- Resolved here so the page can link to the thing the
                    -- message was about. The reference is stored as a type plus
                    -- an id; a template that built a URL out of those two would
                    -- be guessing at a reference format it cannot see.
                    so.sub_number AS order_ref,
                    t.ticket_ref  AS ticket_ref
               FROM notifications n
               LEFT JOIN seller_orders so
                      ON n.reference_type = 'seller_order' AND so.id = n.reference_id
               LEFT JOIN support_tickets t
                      ON n.reference_type = 'support_ticket' AND t.id = n.reference_id
                     AND t.user_id = n.user_id
              WHERE n.user_id = :id AND n.status IN ('delivered', 'queued', 'sending')
              ORDER BY n.created_at DESC
              LIMIT {$limit}",
            ['id' => $userId]
        );
    }

    /**
     * The outbound message log, for the support desk.
     *
     * Every message, whatever happened to it. A skipped one appears as skipped
     * with its reason rather than quietly missing, because "did they get the
     * email?" is the second most common question a desk is asked and "we never
     * sent it, here is why" is a real answer.
     *
     * The payload is NOT returned. It has been scrubbed of codes and tokens on
     * delivery, but a log screen has no use for it either way.
     *
     * @return list<array<string,mixed>>
     */
    public function recentForSupport(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT n.id, n.channel, n.category, n.template_key, n.status, n.skip_reason,
                    n.attempts, n.provider_response, n.created_at, n.sent_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS customer_name
               FROM notifications n
               JOIN users u ON u.id = n.user_id
              ORDER BY n.created_at DESC, n.id DESC
              LIMIT {$limit}"
        );
    }

    public function markRead(int $id, int $userId): int
    {
        // The user id is in the WHERE clause, not checked afterwards: a
        // customer cannot mark somebody else's notification read by guessing an
        // id, because the row is never found in the first place.
        return $this->statement(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
              WHERE id = :id AND user_id = :user_id AND read_at IS NULL',
            ['id' => $id, 'user_id' => $userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM notifications
              WHERE user_id = :id AND status = 'delivered' AND read_at IS NULL",
            ['id' => $userId]
        );
    }

    /** @param array<string,mixed> $payload */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
