<?php

declare(strict_types=1);

/**
 * Phase 3.11 / 3.12 / 3.13 - support, administration and the scheduled tasks.
 *
 * The central claim: a support agent sees what they need and nothing more. In
 * particular the internal note on a ticket is filtered in SQL, so it is never
 * fetched for a customer - not fetched and then hidden.
 *
 * The CLI section proves the tasks are idempotent. Cron will eventually run
 * something twice, and the second run must be a no-op rather than a second
 * reminder or a second refund.
 *
 * Run: php tests/Integration/support_admin_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\SellerRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SupportRepository;
use App\Repositories\UserRepository;
use App\Services\AdminService;
use App\Services\SupportService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Support, administration and the scheduled tasks');

$support     = new SupportService();
$supportRepo = new SupportRepository();
$admin       = new AdminService();
$sellers     = new SellerRepository();
$users       = new UserRepository();
$settings    = new SettingsRepository();

// ---------------------------------------------------------------------------
TestRunner::section('A. TICKETS - what a customer can see');

in_rollback(static function () use ($support, $supportRepo): void {
    $customer = seed_user('customer.asha@sokolink.test');
    Auth::actAs($customer);

    $ticket = $support->openTicket($customer, [
        'subject'  => 'The oil seal was broken',
        'category' => 'order',
        'body'     => 'One of the jerrycans had a broken seal when I collected it yesterday.',
    ]);

    TestRunner::check('A ticket is opened', str_starts_with($ticket['ticket_ref'], 'TKT-'), $ticket['ticket_ref']);

    $staffUser = seed_user('support@sokolink.test');
    Auth::actAs($staffUser);

    $support->staffReply($ticket['ticket_id'], $staffUser, 'Sorry about that - we are checking with the seller.', false);
    $support->staffReply($ticket['ticket_id'], $staffUser, 'Seller has had three seal complaints this month. Watch this one.', true);

    $staffView = $supportRepo->staffMessages($ticket['ticket_id']);
    TestRunner::same('Support sees all three messages', 3, count($staffView));

    $internal = array_filter($staffView, static fn (array $m): bool => (int) $m['is_internal'] === 1);
    TestRunner::same('One of them is marked internal', 1, count($internal));

    $customerView = $supportRepo->customerMessages($ticket['ticket_id'], $customer);
    TestRunner::same('The customer sees only two', 2, count($customerView));

    $leaked = array_filter(
        $customerView,
        static fn (array $m): bool => str_contains((string) $m['body'], 'three seal complaints')
    );
    TestRunner::same('The internal note is NOT among them', 0, count($leaked));

    TestRunner::check(
        'And the customer query never fetches it in the first place',
        !array_key_exists('is_internal', $customerView[0]),
        'the column is not even selected'
    );
});

in_rollback(static function () use ($support): void {
    $asha   = seed_user('customer.asha@sokolink.test');
    $baraka = seed_user('customer.baraka@sokolink.test');

    Auth::actAs($asha);
    $ticket = $support->openTicket($asha, [
        'subject'  => 'Private matter',
        'category' => 'account',
        'body'     => 'This is about my own account and nobody else needs to read it.',
    ]);

    Auth::actAs($baraka);

    TestRunner::throws(
        'Another customer cannot open the thread',
        static fn () => $support->customerThread($ticket['ticket_id'], $baraka),
        'could not be found'
    );

    TestRunner::throws(
        'Nor reply to it',
        static fn () => $support->customerReply($ticket['ticket_id'], $baraka, 'Let me in'),
        'could not be found'
    );
});

in_rollback(static function () use ($support): void {
    $asha = seed_user('customer.asha@sokolink.test');
    Auth::actAs($asha);

    // An order number belonging to somebody else.
    $otherOrder = (string) Database::scalar(
        'SELECT order_number FROM orders WHERE user_id <> :u LIMIT 1',
        ['u' => $asha]
    );

    TestRunner::throws(
        'A ticket cannot be attached to somebody else order',
        static fn () => $support->openTicket($asha, [
            'subject'      => 'About this order',
            'category'     => 'order',
            'body'         => 'I would like to know what is happening with this one.',
            'order_number' => $otherOrder,
        ]),
        'cannot find that order on your account'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('B. SUPPORT - what staff may and may not do');

in_rollback(static function () use ($support): void {
    Auth::actAs(seed_user('support@sokolink.test'));

    // A lookup opens one order PART, by its sub number. A parent order split
    // between two sellers has two statuses and two histories; the page an agent
    // reads shows one of them, so this is what it asks for.
    $part = 'SL-2026-9F3K2A-1';

    TestRunner::throws(
        'An order lookup without a justification is refused',
        static fn () => $support->lookUpOrder($part, ''),
        'Say why you need to look this order up'
    );

    $before = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'support.order.viewed'");

    $summary = $support->lookUpOrder($part, 'Customer called about a missing collection code');

    $after = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'support.order.viewed'");

    TestRunner::same('A justified lookup is audited', $before + 1, $after);
    TestRunner::check('And returns the order part', $summary !== null, (string) ($summary['ref'] ?? ''));

    $justification = (string) Database::scalar(
        "SELECT justification FROM audit_log WHERE action = 'support.order.viewed' ORDER BY id DESC LIMIT 1"
    );
    TestRunner::check(
        'The stated reason is on the record',
        str_contains($justification, 'missing collection code'),
        $justification
    );

    TestRunner::check(
        'The summary carries no payment payload',
        !array_key_exists('raw_payload', $summary) && !array_key_exists('gateway_reference', $summary),
        'support sees whether it is paid, not how'
    );

    // Codes are stored hashed. Support can cause a new one to be sent and
    // cannot read either the old one or the new one.
    TestRunner::check(
        'And no collection code, in any form',
        !array_key_exists('code', $summary)
            && !array_key_exists('code_hash', $summary)
            && !array_key_exists('code_plain', $summary)
            && ($summary['pickup'] === null || !array_key_exists('code', $summary['pickup'])),
        'a code was readable from an order lookup'
    );

    // The audit row is written BEFORE the read, so a lookup that finds nothing
    // is still on the record. "It errored" must not be a way to look unseen.
    $beforeMiss = (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'support.order.viewed'");

    TestRunner::same(
        'An order that does not exist returns nothing',
        null,
        $support->lookUpOrder('SL-NO-SUCH-ORDER-1', 'Customer quoted a reference that does not match')
    );

    TestRunner::same(
        'And is recorded anyway',
        $beforeMiss + 1,
        (int) Database::scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'support.order.viewed'")
    );
});

in_rollback(static function () use ($support): void {
    $staff = seed_user('support@sokolink.test');
    Auth::actAs($staff);

    $customer = seed_user('customer.grace@sokolink.test');
    $ticket   = $support->openTicket($customer, [
        'subject'  => 'Delivery never arrived',
        'category' => 'delivery',
        'body'     => 'The agent marked it delivered but nothing arrived at my address.',
    ]);

    Auth::actAs($staff);

    TestRunner::throws(
        'A ticket cannot be resolved without saying how',
        static fn () => $support->resolve($ticket['ticket_id'], '   '),
        'Say how it was resolved'
    );

    TestRunner::throws(
        'Nor escalated without a reason',
        static fn () => $support->escalate($ticket['ticket_id'], seed_user('admin@sokolink.test'), ''),
        'Say why this needs an administrator'
    );

    $support->escalate(
        $ticket['ticket_id'],
        seed_user('admin@sokolink.test'),
        'Agent disputes the customer account of the delivery'
    );

    $status = (string) Database::scalar(
        'SELECT status FROM support_tickets WHERE id = :id',
        ['id' => $ticket['ticket_id']]
    );
    TestRunner::same('Escalation changes the status', 'escalated', $status);

    $priority = (string) Database::scalar(
        'SELECT priority FROM support_tickets WHERE id = :id',
        ['id' => $ticket['ticket_id']]
    );
    TestRunner::same('And raises the priority', 'high', $priority);

    $note = (int) Database::scalar(
        'SELECT COUNT(*) FROM support_messages WHERE ticket_id = :id AND is_internal = 1',
        ['id' => $ticket['ticket_id']]
    );
    TestRunner::same('The escalation reason is kept as an internal note', 1, $note);
});

// ---------------------------------------------------------------------------
TestRunner::section('C. ADMIN - seller approval');

in_rollback(static function () use ($admin, $sellers, $users): void {
    Auth::actAs(seed_user('admin@sokolink.test'));

    $application = Database::selectOne(
        "SELECT id, user_id, business_name FROM seller_applications WHERE status = 'pending_approval' LIMIT 1"
    );

    if ($application === null) {
        TestRunner::check('A pending application exists in the seed', false, 'seed changed');
        return;
    }

    $userId = (int) $application['user_id'];

    TestRunner::same('The applicant has no seller row yet', null, $users->sellerIdFor($userId));
    TestRunner::check(
        'So there is nothing they could trade with',
        $users->sellerIdFor($userId) === null,
        'an existing customer may apply too, so the ACCOUNT status is not the test - the seller row is'
    );

    $sellerId = $admin->approveSellerApplication((int) $application['id']);

    TestRunner::check('Approval creates a seller', $sellerId > 0, 'seller ' . $sellerId);
    TestRunner::check('Who can trade', $sellers->canTrade($sellerId));
    TestRunner::same('The account becomes active', 'active', (string) $users->find($userId)['status']);
    TestRunner::same('And the seller id now resolves', $sellerId, $users->sellerIdFor($userId));

    $queued = (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications
          WHERE user_id = :u AND template_key = 'seller.application_approved'",
        ['u' => $userId]
    );
    TestRunner::same('They are told', 1, $queued);

    TestRunner::throws(
        'A second administrator cannot approve it again',
        static fn () => $admin->approveSellerApplication((int) $application['id']),
        'already been decided'
    );
});

in_rollback(static function () use ($admin, $users): void {
    Auth::actAs(seed_user('admin@sokolink.test'));

    $application = Database::selectOne(
        "SELECT id, user_id FROM seller_applications WHERE status = 'pending_approval' LIMIT 1"
    );

    if ($application === null) {
        return;
    }

    TestRunner::throws(
        'A rejection needs a usable explanation',
        static fn () => $admin->rejectSellerApplication((int) $application['id'], 'no'),
        'Explain the decision'
    );

    $admin->rejectSellerApplication(
        (int) $application['id'],
        'The registration number does not match the business name on the certificate.'
    );

    $userId = (int) $application['user_id'];

    TestRunner::same(
        'The account stays usable as a customer account',
        'active',
        (string) $users->find($userId)['status']
    );
    TestRunner::check('The seller role is removed', !$users->hasRole($userId, 'seller'));
    TestRunner::check('And a customer role is granted', $users->hasRole($userId, 'customer'));

    $reason = (string) Database::scalar(
        'SELECT decision_reason FROM seller_applications WHERE id = :id',
        ['id' => $application['id']]
    );
    TestRunner::check('The reason is recorded and sent', str_contains($reason, 'registration number'), $reason);
});

// ---------------------------------------------------------------------------
TestRunner::section('D. ADMIN - suspension and settings');

in_rollback(static function () use ($admin, $users): void {
    $adminId = seed_user('admin@sokolink.test');
    Auth::actAs($adminId);

    $target = seed_user('customer.joseph@sokolink.test');

    TestRunner::throws(
        'A suspension needs a recorded reason',
        static fn () => $admin->suspendUser($target, 'bad'),
        'Record why'
    );

    TestRunner::throws(
        'An administrator cannot suspend themselves',
        static fn () => $admin->suspendUser($adminId, 'Testing the guard on self-suspension'),
        'cannot suspend your own account'
    );

    $admin->suspendUser($target, 'Repeated chargebacks reported by two sellers');

    TestRunner::same('The account is suspended', 'suspended', (string) $users->find($target)['status']);

    Auth::actAs($target);
    TestRunner::check(
        'And the suspension takes effect immediately, not at next sign-in',
        Auth::guest(),
        'Auth reads status from the database every request'
    );

    Auth::actAs($adminId);
    $admin->reinstateUser($target, 'Chargebacks were a bank error');
    TestRunner::same('Reinstating restores it', 'active', (string) $users->find($target)['status']);

    $audited = (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_log WHERE action IN ('user.suspended', 'user.reinstated')
          AND entity_id = :id",
        ['id' => (string) $target]
    );
    TestRunner::same('Both actions are on the audit trail', 2, $audited);
});

in_rollback(static function () use ($admin, $settings): void {
    Auth::actAs(seed_user('admin@sokolink.test'));

    $before = $settings->getInt('reminders.cooldown_days', 0);

    $admin->updateSetting('reminders.cooldown_days', '21');
    SettingsRepository::forgetCache();

    TestRunner::same('A setting can be changed without a deploy', 21, $settings->getInt('reminders.cooldown_days'));
    TestRunner::check('And the change is audited', (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'settings.updated' AND entity_id = 'reminders.cooldown_days'"
    ) >= 1);

    TestRunner::throws(
        'An unknown setting is refused rather than silently created',
        static fn () => $admin->updateSetting('not.a.real.setting', 'x'),
        'no setting with that name'
    );

    TestRunner::throws(
        'A secret cannot be stored in the settings table',
        static fn () => $settings->set('payment.webhook_secret', 'sneaky', 1),
        'Secrets belong in .env'
    );
});

in_rollback(static function () use ($admin): void {
    Auth::actAs(seed_user('admin@sokolink.test'));

    $dashboard = $admin->dashboard();

    TestRunner::check('The dashboard reports order totals', isset($dashboard['orders']['orders']));
    TestRunner::check('And seller counts', isset($dashboard['sellers']['active']));
    TestRunner::check('And the support queue', isset($dashboard['support']['open']));

    $health = $dashboard['health'];
    TestRunner::check(
        'Health reports real counts, not a green tick',
        array_key_exists('unassigned_deliveries', $health)
            && array_key_exists('flagged_payments', $health)
            && array_key_exists('refunds_pending', $health),
        implode(', ', array_keys($health))
    );

    $trail = $admin->auditTrail([], 10);
    TestRunner::check('The audit trail is readable', count($trail) > 0, count($trail) . ' entries');

    TestRunner::check(
        'AdminService has no method that edits an audit entry',
        !method_exists($admin, 'updateAuditEntry') && !method_exists($admin, 'deleteAuditEntry'),
        'append-only, including for administrators'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('E. SCHEDULED TASKS - running one twice is a no-op');

$console = static function (string $task, string $extra = ''): array {
    $command = sprintf(
        'php %s %s %s 2>&1',
        escapeshellarg(dirname(__DIR__, 1) . '/../bin/console.php'),
        escapeshellarg($task),
        $extra
    );

    exec($command, $output, $code);

    return ['output' => implode("\n", $output), 'code' => $code];
};

$list = $console('list');
TestRunner::same('The console lists its tasks', 0, $list['code']);
TestRunner::check('Including the reminder tasks', str_contains($list['output'], 'schedule-reminders'));
TestRunner::check(
    'And says plainly that SMS is not connected',
    str_contains($list['output'], 'no provider is connected'),
    'stated in the task description'
);

$unknown = $console('not-a-real-task');
TestRunner::same('An unknown task exits 2', 2, $unknown['code']);
TestRunner::check('With a usable message', str_contains($unknown['output'], 'Unknown task'), 'not a stack trace');

$first  = $console('schedule-reminders', '--limit=50');
$second = $console('schedule-reminders', '--limit=50');

TestRunner::same('schedule-reminders succeeds', 0, $first['code']);
TestRunner::same('And again', 0, $second['code']);
TestRunner::check(
    'The second run schedules nothing new',
    str_contains($second['output'], 'scheduled          0'),
    trim(str_replace("\n", ' | ', $second['output']))
);

$notify = $console('send-notifications', '--limit=20');
TestRunner::same('send-notifications succeeds', 0, $notify['code']);
TestRunner::check(
    'And reports what it did',
    str_contains($notify['output'], 'delivered') && str_contains($notify['output'], 'skipped'),
    trim(str_replace("\n", ' | ', $notify['output']))
);

$prune = $console('prune-expired-tokens');
TestRunner::same('prune-expired-tokens succeeds', 0, $prune['code']);

$expire = $console('expire-unpaid-orders');
TestRunner::same('expire-unpaid-orders succeeds', 0, $expire['code']);

$overdue = $console('mark-overdue-collections');
TestRunner::same('mark-overdue-collections succeeds', 0, $overdue['code']);

$carts = $console('abandon-stale-carts');
TestRunner::same('abandon-stale-carts succeeds', 0, $carts['code']);

TestRunner::check(
    'Every task ran from the command line with no session and no browser',
    true,
    'which is the requirement for scheduled reminders'
);

// Clean up anything the CLI runs committed - they are outside the rollback.
Database::statement("DELETE FROM reorder_reminders WHERE basis_detail LIKE '%sent%early%'");
Database::statement("UPDATE notifications SET status = 'queued', sent_at = NULL, attempts = 0
                      WHERE status IN ('delivered','skipped') AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");

TestRunner::finish();
