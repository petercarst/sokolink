<?php

declare(strict_types=1);

/**
 * CONCURRENCY PROOF: two customers race for the last unit of stock.
 *
 * This is the claim the whole inventory design rests on (FR-INV-04,
 * NFR-DAT-04), so it is proved rather than asserted. The roadmap deferred this
 * to Phase 5; running it in Phase 2 means the schema is not signed off on a
 * promise.
 *
 * Two SEPARATE connections, therefore two separate InnoDB transactions. The
 * script drives them in the interleaving that would break a naive design:
 *
 *      T1  BEGIN
 *      T1  SELECT ... FOR UPDATE          -> takes the row lock
 *      T2  BEGIN
 *      T2  SELECT ... FOR UPDATE          -> BLOCKS on T1's lock
 *                                            (proved by a lock-wait timeout)
 *      T1  guarded UPDATE                 -> reserves the last unit
 *      T1  COMMIT                         -> lock released
 *      T2  guarded UPDATE                 -> matches 0 rows, because the guard
 *                                            is re-evaluated against committed
 *                                            data
 *      T2  ROLLBACK                       -> the customer is told, honestly,
 *                                            that it has gone
 *
 * Without the lock, both transactions would read "1 available" and both would
 * reserve it. Without the guard in the UPDATE's WHERE clause, T2 would
 * overwrite T1's reservation. Both are needed, and the CHECK constraint is the
 * third net underneath.
 *
 * Run:  php tests/Concurrency/oversell_test.php
 * Exit: 0 all assertions passed, 1 otherwise.
 */

const DB_DSN  = 'mysql:host=127.0.0.1;port=3306;dbname=sokolink;charset=utf8mb4';
const DB_USER = 'root';
const DB_PASS = '';

$failures = 0;

function connect(): PDO
{
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Exactly what the application connection will do in Phase 3.
    $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET SESSION time_zone='+00:00'");

    return $pdo;
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s%s", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' - ' . $detail : '', PHP_EOL);
}

echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
echo "OVERSELL CONCURRENCY TEST" . PHP_EOL;
echo str_repeat('=', 74) . PHP_EOL . PHP_EOL;

$setup = connect();

// ---- Arrange: exactly ONE unit available -----------------------------------
$inventoryId = (int) $setup->query(
    'SELECT id FROM inventory ORDER BY id LIMIT 1'
)->fetchColumn();

if ($inventoryId === 0) {
    fwrite(STDERR, "No inventory rows. Import database/schema.sql and database/seed.sql first." . PHP_EOL);
    exit(1);
}

$setup->prepare('UPDATE inventory SET qty_on_hand = 1, qty_reserved = 0 WHERE id = ?')
      ->execute([$inventoryId]);

$before = $setup->prepare('SELECT qty_on_hand, qty_reserved, qty_available FROM inventory WHERE id = ?');
$before->execute([$inventoryId]);
$row = $before->fetch();

echo "Inventory row #{$inventoryId}: on_hand={$row['qty_on_hand']} reserved={$row['qty_reserved']} available={$row['qty_available']}" . PHP_EOL;
echo "Two customers now try to reserve 1 unit each, concurrently." . PHP_EOL . PHP_EOL;

$t1 = connect();
$t2 = connect();
// So T2's block on T1's lock is observable rather than hanging the test.
$t2->exec('SET SESSION innodb_lock_wait_timeout = 2');

// ---- T1 takes the lock ------------------------------------------------------
$t1->beginTransaction();
$stmt = $t1->prepare('SELECT qty_on_hand, qty_reserved FROM inventory WHERE id = ? FOR UPDATE');
$stmt->execute([$inventoryId]);
$t1Read = $stmt->fetch();
check('T1 reads the row and takes the lock', (int) $t1Read['qty_on_hand'] - (int) $t1Read['qty_reserved'] === 1, '1 available');

// ---- T2 blocks on the same row ---------------------------------------------
$t2->beginTransaction();
$t2Blocked = false;
try {
    $stmt = $t2->prepare('SELECT qty_on_hand, qty_reserved FROM inventory WHERE id = ? FOR UPDATE');
    $stmt->execute([$inventoryId]);
} catch (PDOException $e) {
    // 1205 = lock wait timeout. That IS the proof: T2 could not read the row
    // while T1 held it, so the two cannot both see "1 available".
    $t2Blocked = str_contains($e->getMessage(), '1205') || stripos($e->getMessage(), 'lock wait') !== false;
}
check('T2 is blocked by T1 row lock', $t2Blocked, 'lock wait timeout as expected');

// ---- T1 reserves the last unit and commits ----------------------------------
$update = $t1->prepare(
    'UPDATE inventory SET qty_reserved = qty_reserved + :n
      WHERE id = :id AND (qty_on_hand - qty_reserved) >= :n2'
);
$update->execute(['n' => 1, 'id' => $inventoryId, 'n2' => 1]);
check('T1 guarded UPDATE reserves 1', $update->rowCount() === 1, 'affected=' . $update->rowCount());
$t1->commit();

// ---- T2 retries against committed data --------------------------------------
$t2->rollBack();
$t2->beginTransaction();
$stmt = $t2->prepare('SELECT qty_on_hand, qty_reserved FROM inventory WHERE id = ? FOR UPDATE');
$stmt->execute([$inventoryId]);
$t2Read = $stmt->fetch();
check('T2 now sees 0 available', (int) $t2Read['qty_on_hand'] - (int) $t2Read['qty_reserved'] === 0);

$update2 = $t2->prepare(
    'UPDATE inventory SET qty_reserved = qty_reserved + :n
      WHERE id = :id AND (qty_on_hand - qty_reserved) >= :n2'
);
$update2->execute(['n' => 1, 'id' => $inventoryId, 'n2' => 1]);
check('T2 guarded UPDATE matches 0 rows', $update2->rowCount() === 0, 'affected=' . $update2->rowCount() . ' - order must be rejected');
$t2->rollBack();

// ---- The CHECK constraint, if application code bypassed the guard entirely ---
$checkHeld = false;
try {
    $setup->prepare('UPDATE inventory SET qty_reserved = qty_on_hand + 5 WHERE id = ?')
          ->execute([$inventoryId]);
} catch (PDOException $e) {
    $checkHeld = stripos($e->getMessage(), 'chk_inventory_reserved') !== false
              || stripos($e->getMessage(), 'CONSTRAINT') !== false;
}
check('CHECK constraint blocks a direct overwrite', $checkHeld, 'chk_inventory_reserved');

// ---- Final state -------------------------------------------------------------
$before->execute([$inventoryId]);
$after = $before->fetch();
check(
    'Exactly one unit reserved in total',
    (int) $after['qty_reserved'] === 1 && (int) $after['qty_available'] === 0,
    "on_hand={$after['qty_on_hand']} reserved={$after['qty_reserved']} available={$after['qty_available']}"
);

echo PHP_EOL . str_repeat('-', 74) . PHP_EOL;
if ($failures === 0) {
    echo "RESULT: one customer got the unit, the other was cleanly refused." . PHP_EOL;
    echo "        No overselling. All assertions passed." . PHP_EOL;
} else {
    echo "RESULT: {$failures} assertion(s) FAILED - the inventory design is not safe." . PHP_EOL;
}
echo str_repeat('-', 74) . PHP_EOL . PHP_EOL;

exit($failures === 0 ? 0 : 1);
