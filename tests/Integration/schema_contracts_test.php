<?php

declare(strict_types=1);

/**
 * SCHEMA CONTRACT TEST
 *
 * Phase 1 defined a view-model contract for every screen, in app/Views/_mock/.
 * Phase 2 exit criterion 3 says each one must map to a REAL query. This file
 * proves it by running that query and checking the shape and the values.
 *
 * It also checks referential and arithmetic integrity of the seed, and the
 * access-scoping patterns the repositories will use in Phase 3 - because a
 * schema that cannot express "only this seller's orders" cheaply is a schema
 * that will get a bad WHERE clause bolted on later.
 *
 * Run:  php tests/Integration/schema_contracts_test.php
 * Exit: 0 all passed, 1 otherwise.
 */

const DB_DSN  = 'mysql:host=127.0.0.1;port=3306;dbname=sokolink;charset=utf8mb4';
const DB_USER = 'root';
const DB_PASS = '';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-56s %s%s", $ok ? 'PASS' : 'FAIL', $label, $detail, PHP_EOL);
}

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL . str_repeat('-', 74) . PHP_EOL;
}

$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
$pdo->exec("SET SESSION time_zone='+00:00'");

echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
echo 'SCHEMA CONTRACT TEST' . PHP_EOL;
echo str_repeat('=', 74) . PHP_EOL;


// =============================================================================
section('A. INTEGRITY OF THE SEED');

$orphans = (int) $pdo->query(
    'SELECT COUNT(*) FROM seller_orders so
      LEFT JOIN orders o ON o.id = so.order_id
      WHERE o.id IS NULL'
)->fetchColumn();
check('No orphan sub-orders', $orphans === 0);

// Every parent total must equal the sum of its parts. If this drifts, a
// customer receipt and a seller statement disagree.
$mismatch = $pdo->query(
    'SELECT o.order_number, o.grand_total, SUM(so.total) AS parts
       FROM orders o JOIN seller_orders so ON so.order_id = o.id
      GROUP BY o.id
     HAVING ABS(o.grand_total - parts) > 0.001'
)->fetchAll();
check('Parent grand_total equals the sum of its sub-orders', $mismatch === [], count($mismatch) . ' mismatched');

// A column referenced in HAVING must appear in the SELECT list.
$lineMismatch = $pdo->query(
    'SELECT so.sub_number, so.subtotal, SUM(oi.line_total) AS lines_total
       FROM seller_orders so
       JOIN order_items oi ON oi.seller_order_id = so.id
      GROUP BY so.id, so.sub_number, so.subtotal
     HAVING ABS(so.subtotal - SUM(oi.line_total)) > 0.001'
)->fetchAll();
check('Sub-order subtotal equals the sum of its line items', $lineMismatch === [], count($lineMismatch) . ' mismatched');

$badLines = (int) $pdo->query(
    'SELECT COUNT(*) FROM order_items WHERE ABS(line_total - (unit_price * qty)) > 0.001'
)->fetchColumn();
check('Every line_total equals unit_price x qty', $badLines === 0);

$badInv = (int) $pdo->query('SELECT COUNT(*) FROM inventory WHERE qty_reserved > qty_on_hand')->fetchColumn();
check('No inventory row has reserved > on_hand', $badInv === 0);

$hash = (string) $pdo->query("SELECT password_hash FROM users WHERE email='customer.asha@sokolink.test'")->fetchColumn();
check('Seeded password verifies with password_verify()', password_verify('SokoLink!Dev2026', $hash), 'SokoLink!Dev2026');

$plainCode = (string) $pdo->query('SELECT code_hash FROM order_pickups WHERE seller_order_id = 1')->fetchColumn();
check('Collection code is stored hashed, not in plain text',
    strlen($plainCode) === 64 && $plainCode === hash('sha256', 'K7M2QP'),
    'SHA-256, 64 chars');


// =============================================================================
section('B. PUBLIC MARKETPLACE CONTRACTS  (_mock/products.php, stores.php)');

// Catalogue listing: product + seller + aggregated per-store availability.
$rows = $pdo->query(
    "SELECT p.id, p.slug, p.name, p.brand, p.price, p.compare_at_price, p.pack_size,
            c.slug AS category_slug, c.name AS category_name,
            s.business_name AS seller_name, s.slug AS seller_slug,
            COALESCE(SUM(i.qty_available), 0) AS qty_available,
            CASE WHEN COALESCE(SUM(i.qty_available),0) = 0 THEN 'out'
                 WHEN COALESCE(SUM(i.qty_available),0) <= 12 THEN 'low'
                 ELSE 'in' END AS stock_state,
            p.rating_avg, p.rating_count, p.allows_pickup, p.allows_delivery,
            p.is_consumable, p.typical_consumption_days
       FROM products p
       JOIN categories c ON c.id = p.category_id
       JOIN sellers    s ON s.id = p.seller_id
       LEFT JOIN inventory i ON i.product_id = p.id
      WHERE p.status = 'published' AND s.status = 'active'
      GROUP BY p.id
      ORDER BY p.name"
)->fetchAll();
$contract = ['id','slug','name','brand','price','compare_at_price','pack_size','category_slug',
             'category_name','seller_name','seller_slug','qty_available','stock_state',
             'rating_avg','rating_count','allows_pickup','allows_delivery','is_consumable','typical_consumption_days'];
check('Catalogue listing returns every contract key', $rows !== [] && array_diff($contract, array_keys($rows[0])) === [], count($rows) . ' products');

$states = array_count_values(array_column($rows, 'stock_state'));
check('Catalogue exposes in / low / out stock states',
    isset($states['in'], $states['low'], $states['out']),
    'in=' . ($states['in'] ?? 0) . ' low=' . ($states['low'] ?? 0) . ' out=' . ($states['out'] ?? 0));

// Product detail: per-STORE availability, not one global number (FR-INV-01).
$stmt = $pdo->prepare(
    'SELECT st.id, st.name, st.district, st.region, i.qty_available
       FROM inventory i JOIN stores st ON st.id = i.store_id
      WHERE i.product_id = :pid AND st.status = :status
      ORDER BY st.name'
);
$stmt->execute(['pid' => 101, 'status' => 'published']);
$perStore = $stmt->fetchAll();
check('Product detail lists availability PER STORE', count($perStore) === 2, count($perStore) . ' stores for product 101');

// FULLTEXT search.
$stmt = $pdo->prepare(
    "SELECT p.slug, MATCH(p.name, p.brand, p.description) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score
       FROM products p
      WHERE p.status='published'
        AND MATCH(p.name, p.brand, p.description) AGAINST(:q2 IN NATURAL LANGUAGE MODE)
      ORDER BY score DESC"
);
$stmt->execute(['q' => 'cooking oil', 'q2' => 'cooking oil']);
$found = $stmt->fetchAll();
check('FULLTEXT search finds and ranks products', $found !== [], count($found) . ' hits for "cooking oil"');

// Store profile with local opening hours.
$hours = $pdo->query(
    'SELECT sh.day_of_week, sh.opens_at, sh.closes_at, sh.is_closed
       FROM store_hours sh WHERE sh.store_id = 2 ORDER BY sh.day_of_week'
)->fetchAll();
check('Store profile returns 7 days of opening hours', count($hours) === 7,
    'Sunday closed=' . $hours[6]['is_closed']);


// =============================================================================
section('C. ORDER CONTRACTS  (_mock/orders.php)');

// One parent order spanning two sellers - the case a flat table cannot express.
$split = $pdo->query(
    "SELECT o.order_number, COUNT(so.id) AS parts,
            GROUP_CONCAT(CONCAT(so.fulfilment_method,':',so.status) ORDER BY so.id) AS parts_detail
       FROM orders o JOIN seller_orders so ON so.order_id = o.id
      WHERE o.order_number = 'SL-2026-9F3K2A'
      GROUP BY o.id"
)->fetch();
check('One order splits into two independently-tracked parts',
    (int) $split['parts'] === 2, $split['parts_detail']);

// Customer active orders.
$stmt = $pdo->prepare(
    "SELECT so.sub_number, o.order_number, so.status, so.fulfilment_method, so.total,
            s.business_name AS seller_name, st.name AS store_name, o.placed_at
       FROM seller_orders so
       JOIN orders  o  ON o.id  = so.order_id
       JOIN sellers s  ON s.id  = so.seller_id
       JOIN stores  st ON st.id = so.store_id
      WHERE o.user_id = :uid
        AND so.status NOT IN ('completed','collected','delivered','rejected_seller','cancelled_customer','refunded')
      ORDER BY o.placed_at DESC"
);
$stmt->execute(['uid' => 9]);
check('Customer active orders query works', $stmt->rowCount() >= 2, $stmt->rowCount() . ' active for Asha');

// Order timeline.
$stmt = $pdo->prepare(
    'SELECT h.to_status, h.actor_type, h.reason, h.created_at,
            CONCAT(u.first_name, " ", u.last_name) AS actor_name
       FROM order_status_history h
       LEFT JOIN users u ON u.id = h.actor_user_id
      WHERE h.seller_order_id = :id ORDER BY h.created_at'
);
$stmt->execute(['id' => 2]);
$timeline = $stmt->fetchAll();
check('Order status history returns a full timeline', count($timeline) >= 8, count($timeline) . ' transitions');

// A rejection must carry its reason.
$reason = $pdo->query(
    "SELECT reason FROM order_status_history
      WHERE to_status='rejected_seller' AND reason IS NOT NULL LIMIT 1"
)->fetchColumn();
check('A seller rejection records a mandatory reason', $reason !== false, substr((string) $reason, 0, 40) . '...');


// =============================================================================
section('D. ACCESS SCOPING  (how Phase 3 repositories will filter)');

// Seller scoping: WHERE seller_id = :actor
$stmt = $pdo->prepare('SELECT COUNT(*) FROM seller_orders WHERE seller_id = :sid');
$stmt->execute(['sid' => 1]);
$mine = (int) $stmt->fetchColumn();
$all  = (int) $pdo->query('SELECT COUNT(*) FROM seller_orders')->fetchColumn();
check('Seller scoping narrows the result set', $mine > 0 && $mine < $all, "seller 1 sees {$mine} of {$all}");

// Agent scoping: WHERE agent_user_id = :actor
$stmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_tasks WHERE agent_user_id = :aid');
$stmt->execute(['aid' => 6]);
$agentTasks = (int) $stmt->fetchColumn();
$allTasks   = (int) $pdo->query('SELECT COUNT(*) FROM delivery_tasks')->fetchColumn();
check('Agent scoping narrows the result set', $agentTasks > 0 && $agentTasks < $allTasks, "agent 6 sees {$agentTasks} of {$allTasks}");

// Customer scoping: WHERE orders.user_id = :actor
$stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :uid');
$stmt->execute(['uid' => 9]);
$mineOrders = (int) $stmt->fetchColumn();
$allOrders  = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
check('Customer scoping narrows the result set', $mineOrders > 0 && $mineOrders < $allOrders, "customer 9 sees {$mineOrders} of {$allOrders}");

// The index that makes agent scoping cheap.
//
// Asserted as a CANDIDATE (`possible_keys`), not as the plan the optimiser
// picked. On a seed with four delivery tasks a full scan is genuinely cheaper
// and MySQL is right to choose it, so checking `key` here would be testing the
// cost model rather than the schema - and would pass or fail depending on how
// many rows a previous suite happened to leave behind. What this file is
// responsible for is that the index exists and covers the columns the scoping
// query filters on.
$plan = $pdo->query("EXPLAIN SELECT * FROM delivery_tasks WHERE agent_user_id = 6 AND status = 'out_for_delivery'")->fetch();
check(
    'Agent scoping has an index available to it',
    str_contains((string) ($plan['possible_keys'] ?? ''), 'idx_task_agent_status'),
    'possible_keys=' . ($plan['possible_keys'] ?? 'NONE') . ' chosen=' . ($plan['key'] ?? 'none, table is small')
);


// =============================================================================
section('E. SUPPORT - INTERNAL NOTE FILTERING  (FR-SUP-02)');

// The SUPPORT query: everything.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM support_messages WHERE ticket_id = :t');
$stmt->execute(['t' => 2]);
$supportSees = (int) $stmt->fetchColumn();

// The CUSTOMER query: internal rows are excluded in SQL, never fetched.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM support_messages WHERE ticket_id = :t AND is_internal = 0');
$stmt->execute(['t' => 2]);
$customerSees = (int) $stmt->fetchColumn();

check('Support sees every message on the ticket', $supportSees === 3, "{$supportSees} messages");
check('Customer query EXCLUDES internal notes in SQL', $customerSees === 2, "{$customerSees} messages, 1 withheld");
check('The internal note is never fetched for the customer', $supportSees > $customerSees);


// =============================================================================
section('F. RETENTION ENGINE  (FR-CRM-08 to FR-CRM-10)');

$basis = $pdo->query(
    'SELECT basis, COUNT(*) n FROM reorder_reminders GROUP BY basis ORDER BY basis'
)->fetchAll();
$basisMap = array_column($basis, 'n', 'basis');
check('Reminders record HOW the date was estimated',
    isset($basisMap['observed_interval'], $basisMap['seller_hint'], $basisMap['none']),
    implode(' ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($basisMap), $basisMap)));

$skips = $pdo->query(
    'SELECT skip_reason, COUNT(*) n FROM reorder_reminders
      WHERE skip_reason IS NOT NULL GROUP BY skip_reason'
)->fetchAll();
check('Every skipped reminder records WHY', $skips !== [],
    implode(' ', array_map(static fn ($r) => $r['skip_reason'] . '=' . $r['n'], $skips)));

// The consent check the scheduler runs: latest marketing consent per user.
$eligible = $pdo->query(
    "SELECT u.id, u.email,
            (SELECT cr.granted FROM consent_records cr
              WHERE cr.user_id = u.id AND cr.consent_type = 'marketing'
              ORDER BY cr.created_at DESC LIMIT 1) AS marketing_ok
       FROM users u WHERE u.id IN (9, 10, 12) ORDER BY u.id"
)->fetchAll();
$consent = array_column($eligible, 'marketing_ok', 'id');
check('Asha consented and still consents', (int) $consent[9] === 1);
check('Baraka never consented (no row at all)', $consent[10] === null);
check('Grace consented then WITHDREW (latest row wins)', (int) $consent[12] === 0);

// Duplicate prevention, live.
$dupBlocked = false;
try {
    $pdo->prepare(
        'INSERT INTO reorder_reminders (user_id, product_id, cycle_key, basis, last_purchased_at)
         VALUES (9, 101, :ck, :b, :d)'
    )->execute(['ck' => 'so:5', 'b' => 'seller_hint', 'd' => '2026-08-07 12:05:00']);
} catch (PDOException $e) {
    $dupBlocked = str_contains($e->getMessage(), 'uq_reminder_cycle');
}
check('A duplicate reminder for the same cycle is impossible', $dupBlocked, 'uq_reminder_cycle');

// The notification worker's hot path.
//
// This asserts the index is AVAILABLE to the query, not that the optimiser
// picks it. On a seeded database the table holds a handful of rows, and
// scanning ten rows really is cheaper than descending an index - MariaDB says
// so, and it is right. Asserting on `key` therefore tested the row count
// rather than the schema, and flipped whenever the notification mix changed.
// `possible_keys` is the thing the schema actually controls.
$plan = $pdo->query("EXPLAIN SELECT * FROM notifications WHERE status='queued' AND send_after <= UTC_TIMESTAMP() ORDER BY send_after LIMIT 50")->fetch();
check(
    'Notification queue lookup can use idx_notif_due',
    str_contains((string) ($plan['possible_keys'] ?? ''), 'idx_notif_due'),
    'possible_keys=' . ($plan['possible_keys'] ?? 'NONE') . ', chosen=' . ($plan['key'] ?: 'full scan, table is tiny')
);


// =============================================================================
section('G. PAYMENTS');

$replayBlocked = false;
try {
    $pdo->prepare(
        'INSERT INTO payment_transactions (transaction_ref, order_id, gateway, gateway_reference, amount, status)
         VALUES (:r, 1, :g, :gr, 80900.00, :s)'
    )->execute(['r' => 'TXN-REPLAY', 'g' => 'sandbox', 'gr' => 'SBX-8f31c2a9', 's' => 'paid']);
} catch (PDOException $e) {
    $replayBlocked = str_contains($e->getMessage(), 'uq_txn_gateway_reference');
}
check('A replayed webhook cannot credit an order twice', $replayBlocked, 'uq_txn_gateway_reference');

$noCreds = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='sokolink'
        AND (column_name LIKE '%card%' OR column_name LIKE '%cvv%'
          OR column_name LIKE '%pin%'  OR column_name LIKE '%pan%')"
)->fetchColumn();
check('No column anywhere could hold a payment credential', (int) $noCreds === 0, 'card/cvv/pin/pan columns: ' . $noCreds);

$refund = $pdo->query('SELECT requested_by, approved_by FROM refunds LIMIT 1')->fetch();
check('Refund request and approval are separate columns',
    array_key_exists('requested_by', $refund) && array_key_exists('approved_by', $refund));


// =============================================================================
section('H. SCHEMA HYGIENE');

$nonInnoDb = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema='sokolink' AND engine <> 'InnoDB'"
)->fetchColumn();
check('Every table is InnoDB', $nonInnoDb === 0);

$badCollation = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema='sokolink' AND table_collation <> 'utf8mb4_unicode_ci'"
)->fetchColumn();
check('Every table uses utf8mb4_unicode_ci', $badCollation === 0);

$moneyCols = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='sokolink'
        AND (column_name LIKE '%price%' OR column_name LIKE '%total%'
          OR column_name LIKE '%amount%' OR column_name LIKE '%fee%')
        AND data_type NOT IN ('decimal')"
)->fetchColumn();
check('No money column uses a float type', $moneyCols === 0, 'non-decimal money columns: ' . $moneyCols);

$fkCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='sokolink'"
)->fetchColumn();
$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sokolink'"
)->fetchColumn();
check('Foreign keys are defined throughout', $fkCount >= 50, "{$fkCount} FKs across {$tableCount} tables");

// Tables that must never be updated by application code.
foreach (['audit_log', 'order_status_history', 'stock_movements', 'consent_records', 'delivery_events'] as $t) {
    $hasUpdatedAt = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema='sokolink' AND table_name='{$t}' AND column_name='updated_at'"
    )->fetchColumn();
    check("Append-only table {$t} has no updated_at column", $hasUpdatedAt === 0);
}

$timestampCols = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema='sokolink' AND data_type = 'timestamp'"
)->fetchColumn();
check('No TIMESTAMP columns (all DATETIME, stored UTC)', $timestampCols === 0,
    'TIMESTAMP auto-converts by session zone; DATETIME does not');


// =============================================================================
echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
printf('RESULT: %d passed, %d failed%s', $pass, $fail, PHP_EOL);
echo str_repeat('=', 74) . PHP_EOL . PHP_EOL;

exit($fail === 0 ? 0 : 1);
