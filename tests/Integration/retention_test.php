<?php

declare(strict_types=1);

/**
 * Phase 3.9 / 3.10 - notifications and the reorder reminder engine.
 *
 * This file exists to test the two rules the brief states most firmly:
 *
 *   "Do not automatically assume that a customer has run out of a product
 *    merely because a fixed number of days has passed."
 *
 *   "Do not pretend messages are being sent through providers that are not
 *    connected."
 *
 * So the assertions that matter are the ones where NOTHING happens: the
 * customer who never consented, the one who withdrew, the product nobody can
 * estimate, and the SMS that is recorded as skipped rather than sent.
 *
 * Run: php tests/Integration/retention_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\ReminderBasis;
use App\Domain\Enums\SkipReason;
use App\Repositories\ConsentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReminderRepository;
use App\Services\NotificationService;
use App\Services\Retention\ConsumptionEstimator;
use App\Services\Retention\ReminderService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Notifications and the reorder reminder engine');

$estimator     = new ConsumptionEstimator();
$reminders     = new ReminderService();
$reminderRepo  = new ReminderRepository();
$consent       = new ConsentRepository();
$notifications = new NotificationRepository();
$notifier      = new NotificationService();

/**
 * Moves the quiet-hours window so it does NOT cover the current local time.
 *
 * Without this, whether a reminder is suppressed depends on what time the suite
 * is run - which is exactly the kind of test that passes all day and fails at
 * ten at night. The window is set inside the rollback, so nothing persists.
 */
function quiet_hours_off(): void
{
    $localHour = (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format('G');

    // A one-hour window on the opposite side of the clock.
    $start = ($localHour + 6) % 24;
    $end   = ($start + 1) % 24;

    set_quiet_hours($start, $end);
}

/** Puts the quiet-hours window over the current local time. */
function quiet_hours_on(): void
{
    $localHour = (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format('G');

    set_quiet_hours($localHour, ($localHour + 2) % 24);
}

function set_quiet_hours(int $start, int $end): void
{
    Database::statement(
        "UPDATE settings SET setting_value = :v WHERE setting_key = 'notify.quiet_hours_start'",
        ['v' => (string) $start]
    );
    Database::statement(
        "UPDATE settings SET setting_value = :v WHERE setting_key = 'notify.quiet_hours_end'",
        ['v' => (string) $end]
    );

    App\Repositories\SettingsRepository::forgetCache();
}

// ---------------------------------------------------------------------------
TestRunner::section('A. THE ESTIMATOR - evidence, ranked');

in_rollback(static function () use ($estimator): void {
    // A consumable with seller guidance.
    $product = Database::selectOne(
        'SELECT id, typical_consumption_days FROM products
          WHERE is_consumable = 1 AND typical_consumption_days IS NOT NULL LIMIT 1'
    );

    $stranger = seed_user('customer.baraka@sokolink.test');

    $one = $estimator->estimate($stranger, (int) $product['id'], 1, gmdate('Y-m-d H:i:s'));
    $two = $estimator->estimate($stranger, (int) $product['id'], 2, gmdate('Y-m-d H:i:s'));

    TestRunner::same(
        'With no history, the seller hint is used',
        ReminderBasis::SellerHint,
        $one['basis']
    );

    TestRunner::check(
        'Buying twice as much means waiting about twice as long',
        $two['days'] === $one['days'] * 2,
        sprintf('%d days for 1, %d days for 2', $one['days'], $two['days'])
    );

    TestRunner::check(
        'The reminder is scheduled BEFORE they run out, not after',
        strtotime((string) $one['next_due_at'] . ' UTC') < time() + ($one['days'] * 86400),
        $one['detail']
    );

    TestRunner::check(
        'And the reasoning is recorded in words',
        str_contains($one['detail'], 'Seller guidance'),
        $one['detail']
    );
});

in_rollback(static function () use ($estimator): void {
    // Karatasi Kitchen Roll: seeded with no seller hint AND no category
    // default, so the estimator has nothing at all to work from.
    $product = Database::selectOne(
        'SELECT p.id, p.name FROM products p
           JOIN categories c ON c.id = p.category_id
          WHERE p.is_consumable = 1
            AND p.typical_consumption_days IS NULL
            AND c.default_consumption_days IS NULL
          LIMIT 1'
    );

    TestRunner::check(
        'The seed contains a consumable nobody can estimate',
        $product !== null,
        $product === null ? 'missing' : (string) $product['name']
    );

    if ($product === null) {
        return;
    }

    $result = $estimator->estimate(
        seed_user('customer.baraka@sokolink.test'),
        (int) $product['id'],
        1,
        gmdate('Y-m-d H:i:s')
    );

    TestRunner::same('With nothing to go on, the basis is "none"', ReminderBasis::None, $result['basis']);
    TestRunner::same('No date is produced', null, $result['next_due_at']);
    TestRunner::check(
        'And the reason says so plainly',
        str_contains($result['detail'], 'nothing is scheduled'),
        $result['detail']
    );
});

in_rollback(static function () use ($estimator): void {
    $customer = seed_user('customer.asha@sokolink.test');
    $product  = Database::selectOne(
        'SELECT id, typical_consumption_days FROM products
          WHERE is_consumable = 1 AND typical_consumption_days IS NOT NULL LIMIT 1'
    );

    // Build a purchase history: bought on a 20-day rhythm, three times.
    $storeId = (int) Database::scalar(
        'SELECT store_id FROM inventory WHERE product_id = :p LIMIT 1',
        ['p' => $product['id']]
    );
    $sellerId = (int) Database::scalar('SELECT seller_id FROM products WHERE id = :p', ['p' => $product['id']]);

    foreach ([60, 40, 20] as $daysAgo) {
        $orderId = Database::insert('orders', [
            'order_number'   => 'SL-TEST-' . bin2hex(random_bytes(3)),
            'user_id'        => $customer,
            'contact_email'  => 'x@y.test', 'contact_phone' => '0700', 'contact_name' => 'Test',
            'payment_status' => 'paid',
            'grand_total'    => '1000.00',
            'placed_at'      => gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400)),
        ]);

        $subId = Database::insert('seller_orders', [
            'order_id' => $orderId, 'sub_number' => 'SL-TEST-' . bin2hex(random_bytes(3)) . '-1',
            'seller_id' => $sellerId, 'store_id' => $storeId,
            'fulfilment_method' => 'pickup', 'status' => 'completed',
            'subtotal' => '1000.00', 'total' => '1000.00',
        ]);

        Database::insert('order_items', [
            'seller_order_id' => $subId, 'product_id' => (int) $product['id'],
            'name_snapshot' => 'Test', 'sku_snapshot' => 'T', 'pack_size_snapshot' => '1',
            'unit_price' => '1000.00', 'qty' => 1, 'line_total' => '1000.00',
        ]);
    }

    $observed = $estimator->observedIntervalDays($customer, (int) $product['id']);

    TestRunner::same('The observed interval is the median of the real gaps', 20, $observed);

    $result = $estimator->estimate($customer, (int) $product['id'], 1, gmdate('Y-m-d H:i:s'));

    TestRunner::same(
        'The customer own rhythm BEATS the seller hint',
        ReminderBasis::ObservedInterval,
        $result['basis']
    );
    TestRunner::check(
        'Even when the seller says something different',
        (int) $product['typical_consumption_days'] !== $observed,
        sprintf('seller says %d, this customer does %d', (int) $product['typical_consumption_days'], $observed)
    );
});

in_rollback(static function () use ($estimator): void {
    $customer = seed_user('customer.grace@sokolink.test');
    $product  = Database::selectOne('SELECT id FROM products WHERE is_consumable = 1 LIMIT 1');

    $storeId  = (int) Database::scalar('SELECT store_id FROM inventory WHERE product_id = :p LIMIT 1', ['p' => $product['id']]);
    $sellerId = (int) Database::scalar('SELECT seller_id FROM products WHERE id = :p', ['p' => $product['id']]);

    // Two purchases a single day apart - somebody topping up an order, not a
    // 1-day consumption cycle.
    foreach ([30, 29] as $daysAgo) {
        $orderId = Database::insert('orders', [
            'order_number'   => 'SL-TOPUP-' . bin2hex(random_bytes(3)),
            'user_id'        => $customer,
            'contact_email'  => 'x@y.test', 'contact_phone' => '0700', 'contact_name' => 'Test',
            'payment_status' => 'paid', 'grand_total' => '500.00',
            'placed_at'      => gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400)),
        ]);

        $subId = Database::insert('seller_orders', [
            'order_id' => $orderId, 'sub_number' => 'SL-TOPUP-' . bin2hex(random_bytes(3)) . '-1',
            'seller_id' => $sellerId, 'store_id' => $storeId,
            'fulfilment_method' => 'pickup', 'status' => 'completed',
            'subtotal' => '500.00', 'total' => '500.00',
        ]);

        Database::insert('order_items', [
            'seller_order_id' => $subId, 'product_id' => (int) $product['id'],
            'name_snapshot' => 'Test', 'sku_snapshot' => 'T', 'pack_size_snapshot' => '1',
            'unit_price' => '500.00', 'qty' => 1, 'line_total' => '500.00',
        ]);
    }

    TestRunner::same(
        'A one-day gap is discarded as a top-up, not treated as a daily habit',
        null,
        $estimator->observedIntervalDays($customer, (int) $product['id'])
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('B. SCHEDULING - and running it twice');

in_rollback(static function () use ($reminders): void {
    $first  = $reminders->schedule();
    $second = $reminders->schedule();

    TestRunner::check('The first run schedules reminders', $first['considered'] > 0, $first['considered'] . ' candidates');
    TestRunner::same('The second run schedules NOTHING new', 0, $second['scheduled']);
    TestRunner::same('And considers nothing new either', 0, $second['considered']);

    TestRunner::check(
        'Each reminder records how its date was estimated',
        array_sum($first['by_basis']) === $first['considered'],
        json_encode($first['by_basis'])
    );
});

in_rollback(static function () use ($reminderRepo): void {
    $userId    = seed_user('customer.asha@sokolink.test');
    $productId = (int) Database::scalar('SELECT id FROM products LIMIT 1');

    $a = $reminderRepo->schedule(
        $userId, $productId, 'dup-test-cycle',
        ReminderBasis::SellerHint, 'test', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')
    );

    $b = $reminderRepo->schedule(
        $userId, $productId, 'dup-test-cycle',
        ReminderBasis::SellerHint, 'test again', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')
    );

    TestRunner::check('The first insert succeeds', $a !== null, 'id ' . $a);
    TestRunner::same('The duplicate is refused by the unique key', null, $b);

    TestRunner::same(
        'Exactly one row exists for the cycle',
        1,
        (int) Database::scalar(
            'SELECT COUNT(*) FROM reorder_reminders WHERE user_id = :u AND product_id = :p AND cycle_key = :c',
            ['u' => $userId, 'p' => $productId, 'c' => 'dup-test-cycle']
        )
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('C. SUPPRESSION - who does NOT get messaged, and why');

/**
 * Builds a due reminder for a customer and returns the row the dispatcher
 * would see.
 *
 * @return array<string,mixed>
 */
function due_reminder_for(string $email, int $lastPurchasedDaysAgo = 0): array
{
    $userId  = seed_user($email);
    $product = Database::selectOne(
        "SELECT p.id, p.name, p.slug, p.status FROM products p
           JOIN inventory i ON i.product_id = p.id
          WHERE p.status = 'published' AND p.is_consumable = 1 AND i.qty_available > 0
          LIMIT 1"
    );

    // Dated NOW by default. These seeded customers already have real purchase
    // history, so a fixture dated weeks back would read as "already
    // repurchased" because of the seed rather than because of the rule under
    // test.
    $lastPurchased = gmdate('Y-m-d H:i:s', time() - ($lastPurchasedDaysAgo * 86400));

    $id = Database::insert('reorder_reminders', [
        'user_id'           => $userId,
        'product_id'        => (int) $product['id'],
        'cycle_key'         => 'suppression-' . bin2hex(random_bytes(4)),
        'basis'             => 'seller_hint',
        'basis_detail'      => 'test fixture',
        'last_purchased_at' => $lastPurchased,
        'next_due_at'       => gmdate('Y-m-d H:i:s', time() - 3600),
        'state'             => 'scheduled',
    ]);

    return [
        'id'                => $id,
        'user_id'           => $userId,
        'product_id'        => (int) $product['id'],
        'product_name'      => (string) $product['name'],
        'product_slug'      => (string) $product['slug'],
        'product_status'    => (string) $product['status'],
        'last_purchased_at' => $lastPurchased,
        'basis'             => 'seller_hint',
        'first_name'        => 'Test',
    ];
}

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    // Baraka never consented - there is no marketing row for him at all.
    $reminder = due_reminder_for('customer.baraka@sokolink.test');

    TestRunner::same(
        'A customer who NEVER consented is skipped as no_consent',
        SkipReason::NoConsent,
        $reminders->suppressionFor($reminder)
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    // Grace consented and then withdrew.
    $reminder = due_reminder_for('customer.grace@sokolink.test');

    TestRunner::same(
        'A customer who WITHDREW is skipped as consent_withdrawn',
        SkipReason::ConsentWithdrawn,
        $reminders->suppressionFor($reminder)
    );

    TestRunner::check(
        'The two are recorded differently, because they are different facts',
        SkipReason::ConsentWithdrawn !== SkipReason::NoConsent
    );
});

in_rollback(static function () use ($reminders, $consent): void {
    quiet_hours_off();

    $reminder = due_reminder_for('customer.asha@sokolink.test');

    // Asha consents, so nothing should suppress this one yet.
    TestRunner::same(
        'A consenting customer with no other obstacle is NOT suppressed',
        null,
        $reminders->suppressionFor($reminder)
    );

    // Now switch the reorder channel off, keeping consent.
    $consent->setChannel(
        (int) $reminder['user_id'],
        NotificationCategory::Reorder,
        NotificationChannel::Email,
        false
    );

    TestRunner::same(
        'Turning the channel off suppresses it, even with consent intact',
        SkipReason::NoConsent,
        $reminders->suppressionFor($reminder)
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    $reminder = due_reminder_for('customer.asha@sokolink.test', 40);

    // They bought it again after the purchase this reminder is about.
    $storeId  = (int) Database::scalar('SELECT store_id FROM inventory WHERE product_id = :p LIMIT 1', ['p' => $reminder['product_id']]);
    $sellerId = (int) Database::scalar('SELECT seller_id FROM products WHERE id = :p', ['p' => $reminder['product_id']]);

    $orderId = Database::insert('orders', [
        'order_number'  => 'SL-AGAIN-' . bin2hex(random_bytes(3)),
        'user_id'       => $reminder['user_id'],
        'contact_email' => 'x@y.test', 'contact_phone' => '0700', 'contact_name' => 'Test',
        'payment_status' => 'paid', 'grand_total' => '1000.00',
        'placed_at'     => gmdate('Y-m-d H:i:s', time() - 86400),
    ]);

    $subId = Database::insert('seller_orders', [
        'order_id' => $orderId, 'sub_number' => 'SL-AGAIN-' . bin2hex(random_bytes(3)) . '-1',
        'seller_id' => $sellerId, 'store_id' => $storeId,
        'fulfilment_method' => 'pickup', 'status' => 'completed',
        'subtotal' => '1000.00', 'total' => '1000.00',
    ]);

    Database::insert('order_items', [
        'seller_order_id' => $subId, 'product_id' => $reminder['product_id'],
        'name_snapshot' => 'Test', 'sku_snapshot' => 'T', 'pack_size_snapshot' => '1',
        'unit_price' => '1000.00', 'qty' => 1, 'line_total' => '1000.00',
    ]);

    TestRunner::same(
        'Somebody who already bought it again is not reminded',
        SkipReason::AlreadyRepurchased,
        $reminders->suppressionFor($reminder)
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    $reminder = due_reminder_for('customer.asha@sokolink.test');

    Database::statement(
        "UPDATE products SET status = 'archived' WHERE id = :p",
        ['p' => $reminder['product_id']]
    );
    $reminder['product_status'] = 'archived';

    TestRunner::same(
        'A delisted product is not advertised back to the customer',
        SkipReason::Unavailable,
        $reminders->suppressionFor($reminder)
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    $reminder = due_reminder_for('customer.asha@sokolink.test');

    Database::statement(
        'UPDATE inventory SET qty_on_hand = qty_reserved WHERE product_id = :p',
        ['p' => $reminder['product_id']]
    );

    TestRunner::same(
        'Nor is a product that has sold out everywhere',
        SkipReason::Unavailable,
        $reminders->suppressionFor($reminder)
    );
});

in_rollback(static function () use ($reminders, $notifications): void {
    quiet_hours_off();

    $reminder = due_reminder_for('customer.asha@sokolink.test');

    // A reminder sent yesterday, well inside the 14-day cooldown.
    $notificationId = $notifications->queue(
        (int) $reminder['user_id'],
        NotificationChannel::Email,
        NotificationCategory::Reorder,
        'reorder.reminder',
        [],
        true
    );
    Database::statement(
        "UPDATE notifications SET status = 'delivered', sent_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
          WHERE id = :id",
        ['id' => $notificationId]
    );
    Database::insert('reorder_reminders', [
        'user_id' => $reminder['user_id'], 'product_id' => $reminder['product_id'],
        'cycle_key' => 'recent-' . bin2hex(random_bytes(4)),
        'basis' => 'seller_hint', 'last_purchased_at' => gmdate('Y-m-d H:i:s'),
        'state' => 'sent', 'notification_id' => $notificationId,
    ]);

    TestRunner::same(
        'A reminder inside the cooldown is held back',
        SkipReason::Cooldown,
        $reminders->suppressionFor($reminder)
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('D. DISPATCH - every skip leaves a record');

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    // Make sure there is something due for each of the three consent states.
    due_reminder_for('customer.asha@sokolink.test');
    due_reminder_for('customer.baraka@sokolink.test');
    due_reminder_for('customer.grace@sokolink.test');

    $result = $reminders->dispatch();

    TestRunner::check('The dispatcher finds due reminders', $result['due'] >= 3, $result['due'] . ' due');
    TestRunner::check('Some are sent', $result['sent'] >= 1, $result['sent'] . ' sent');
    TestRunner::check('Some are skipped', $result['skipped'] >= 2, $result['skipped'] . ' skipped');

    TestRunner::check(
        'And every skip has a stated reason',
        array_sum($result['by_reason']) === $result['skipped'],
        json_encode($result['by_reason'])
    );

    $unexplained = (int) Database::scalar(
        "SELECT COUNT(*) FROM reorder_reminders WHERE state = 'skipped' AND skip_reason IS NULL"
    );
    TestRunner::same('No reminder is skipped without a reason on the row', 0, $unexplained);

    $skippedNotifications = (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications
          WHERE status = 'skipped' AND skip_reason IS NOT NULL
            AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)"
    );
    TestRunner::check(
        'A suppressed message is recorded as skipped, not silently dropped',
        $skippedNotifications >= 2,
        $skippedNotifications . ' skipped rows'
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_off();

    due_reminder_for('customer.asha@sokolink.test');

    $first  = $reminders->dispatch();
    $second = $reminders->dispatch();

    TestRunner::same(
        'Running the dispatcher twice sends nothing a second time',
        0,
        $second['sent']
    );
    TestRunner::same('Because nothing is due any more', 0, $second['due']);
});

// ---------------------------------------------------------------------------
TestRunner::section('E. NOTIFICATIONS - what is actually connected');

in_rollback(static function () use ($notifier): void {
    $channels = $notifier->channelStatus();

    $email = array_values(array_filter($channels, static fn (array $c): bool => $c['channel'] === 'email'))[0];
    $sms   = array_values(array_filter($channels, static fn (array $c): bool => $c['channel'] === 'sms'))[0];
    $whats = array_values(array_filter($channels, static fn (array $c): bool => $c['channel'] === 'whatsapp'))[0];

    TestRunner::check('Email is available', $email['available']);
    TestRunner::check(
        'And says it writes to a log in development rather than sending',
        str_contains($email['reason'], 'mail.log'),
        $email['reason']
    );

    TestRunner::check('SMS is NOT available', !$sms['available'], $sms['reason']);
    TestRunner::check('WhatsApp is NOT available', !$whats['available'], $whats['reason']);
    TestRunner::check(
        'Both say plainly that no provider is connected',
        str_contains($sms['reason'], 'No SMS provider') && str_contains($whats['reason'], 'No WhatsApp'),
        'no pretending'
    );
});

in_rollback(static function () use ($notifier, $notifications): void {
    $userId = seed_user('customer.asha@sokolink.test');

    $emailId = $notifications->queue(
        $userId, NotificationChannel::Email, NotificationCategory::OrderUpdates, 'order.accepted',
        ['sub_number' => 'SL-TEST-1']
    );

    $smsId = $notifications->queue(
        $userId, NotificationChannel::Sms, NotificationCategory::OrderUpdates, 'order.accepted',
        ['sub_number' => 'SL-TEST-1']
    );

    $result = $notifier->dispatchQueue();

    TestRunner::check('The queue is drained', $result['attempted'] >= 2, $result['attempted'] . ' attempted');

    $emailStatus = (string) Database::scalar('SELECT status FROM notifications WHERE id = :id', ['id' => $emailId]);
    $smsStatus   = (string) Database::scalar('SELECT status FROM notifications WHERE id = :id', ['id' => $smsId]);
    $smsReason   = (string) Database::scalar('SELECT skip_reason FROM notifications WHERE id = :id', ['id' => $smsId]);

    TestRunner::same('The email is delivered', 'delivered', $emailStatus);
    TestRunner::same('The SMS is SKIPPED, not delivered', 'skipped', $smsStatus);
    TestRunner::same('And says why', 'skipped_no_provider', $smsReason);

    TestRunner::check(
        'Nothing claims the SMS was sent',
        (int) Database::scalar(
            "SELECT COUNT(*) FROM notifications WHERE id = :id AND sent_at IS NOT NULL",
            ['id' => $smsId]
        ) === 0,
        'sent_at stays null'
    );
});

in_rollback(static function () use ($notifier): void {
    $rendered = $notifier->render([
        'template_key' => 'pickup.code_issued',
        'payload_json' => json_encode(['code' => 'K7M2QP', 'store_name' => 'Mama Lishe', 'window_hours' => 72, 'sub_number' => 'SL-1-1']),
        'first_name'   => 'Asha',
    ]);

    TestRunner::check('The collection code template names the store', str_contains($rendered['body'], 'Mama Lishe'));
    TestRunner::check('And carries the code', str_contains($rendered['body'], 'K7M2QP'));
    TestRunner::check('And says it is single use', str_contains($rendered['body'], 'once'), 'single-use stated');

    $reminder = $notifier->render([
        'template_key' => 'reorder.reminder',
        'payload_json' => json_encode(['product' => 'Cooking oil', 'basis' => 'observed_interval', 'last_bought' => '2026-08-01 10:00:00', 'product_slug' => 'oil']),
        'first_name'   => 'Asha',
    ]);

    TestRunner::check(
        'A reminder explains WHY it was sent',
        str_contains($reminder['body'], 'how often you have bought it before'),
        'the basis is stated in the message itself'
    );
    TestRunner::check(
        'And how to stop them',
        str_contains($reminder['body'], 'unsubscribe'),
        'every marketing message carries an opt-out'
    );

    $unknown = $notifier->render(['template_key' => 'does.not.exist', 'payload_json' => '{}']);
    TestRunner::check(
        'An unknown template degrades readably instead of throwing',
        str_contains($unknown['body'], 'No template is defined'),
        $unknown['subject']
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('F. CUSTOMER CONTROL');

in_rollback(static function () use ($consent, $reminders): void {
    $userId = seed_user('customer.asha@sokolink.test');

    TestRunner::check('Asha currently consents', $consent->currentlyGrants($userId, ConsentType::Marketing));

    $consent->unsubscribeAll($userId);

    TestRunner::check(
        'Unsubscribing withdraws consent',
        !$consent->currentlyGrants($userId, ConsentType::Marketing),
        'a new row, not an edit'
    );

    TestRunner::check(
        'And switches the reorder channel off',
        !$consent->channelEnabled($userId, NotificationCategory::Reorder, NotificationChannel::Email)
    );

    TestRunner::check(
        'But leaves order updates alone - those are not marketing',
        $consent->channelEnabled($userId, NotificationCategory::OrderUpdates, NotificationChannel::Email),
        'you still get told your order is ready'
    );

    $history = $consent->historyFor($userId, ConsentType::Marketing);
    TestRunner::check(
        'The whole consent history is preserved',
        count($history) >= 2,
        count($history) . ' rows - append-only'
    );
});

in_rollback(static function () use ($reminders, $reminderRepo): void {
    $reminder = due_reminder_for('customer.asha@sokolink.test');
    $userId   = (int) $reminder['user_id'];

    $reminders->snooze((int) $reminder['id'], $userId, 30);

    $due = (string) Database::scalar(
        'SELECT next_due_at FROM reorder_reminders WHERE id = :id',
        ['id' => $reminder['id']]
    );

    TestRunner::check(
        'A customer can push a reminder back',
        strtotime($due . ' UTC') > time() + (25 * 86400),
        $due
    );

    $reminders->dismiss((int) $reminder['id'], $userId);

    TestRunner::same(
        'Or close it entirely',
        'closed',
        (string) Database::scalar('SELECT state FROM reorder_reminders WHERE id = :id', ['id' => $reminder['id']])
    );

    $other = seed_user('customer.baraka@sokolink.test');

    TestRunner::throws(
        'And cannot touch somebody else reminder',
        static fn () => $reminders->snooze((int) $reminder['id'], $other, 7),
        'could not be found'
    );
});

in_rollback(static function () use ($reminders): void {
    quiet_hours_on();

    $reminder = due_reminder_for('customer.asha@sokolink.test');

    TestRunner::same(
        'A reminder inside the customer quiet hours is held back',
        SkipReason::QuietHours,
        $reminders->suppressionFor($reminder)
    );

    quiet_hours_off();

    TestRunner::same(
        'And goes out once the quiet window has passed',
        null,
        $reminders->suppressionFor($reminder)
    );
});

TestRunner::finish();
