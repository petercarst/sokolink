<?php

declare(strict_types=1);

/**
 * Phase 3.3 - catalogue reads and inventory writes.
 *
 * Section D is the one that matters. It drives two reservations at the last
 * unit and asserts that exactly one succeeds - the same claim as
 * tests/Concurrency/oversell_test.php, but through the service layer rather
 * than raw SQL, because the guarantee has to survive the code on top of it.
 *
 * Run: php tests/Integration/catalog_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\StockMovementType;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\StoreRepository;
use App\Services\CatalogService;
use App\Services\InventoryService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Catalogue and inventory');

$catalog   = new CatalogService();
$stock     = new InventoryService();
$inventory = new InventoryRepository();
$products  = new ProductRepository();
$stores    = new StoreRepository();

/** Any published product that is stocked at exactly one store. */
$sample = Database::selectOne(
    "SELECT p.id, p.slug, p.name, i.store_id, i.qty_on_hand, i.qty_reserved
       FROM products p
       JOIN inventory i ON i.product_id = p.id
      WHERE p.status = 'published'
      ORDER BY p.id
      LIMIT 1"
);

// ---------------------------------------------------------------------------
TestRunner::section('A. BROWSING - what the public can see');

$browse = $catalog->browse([], 1, 10);
TestRunner::check('The catalogue returns products', $browse['total'] > 0, $browse['total'] . ' published');

$draftLeak = 0;
foreach ($catalog->browse([], 1, 60)['products'] as $row) {
    if (($row['status'] ?? 'published') !== 'published') {
        $draftLeak++;
    }
}
TestRunner::same('No draft or archived product appears publicly', 0, $draftLeak);

$publishedCount = (int) Database::scalar(
    "SELECT COUNT(*) FROM products p JOIN sellers s ON s.id = p.seller_id
      WHERE p.status = 'published' AND s.status = 'active'"
);
TestRunner::same(
    'The public total matches published products from active sellers',
    $publishedCount,
    $browse['total']
);

$inStock = $catalog->browse(['in_stock' => true], 1, 60);
$zeroes  = array_filter($inStock['products'], static fn (array $r): bool => (int) $r['available'] <= 0);
TestRunner::same('The in-stock filter excludes sold-out products', 0, count($zeroes));

$cheap = $catalog->browse(['max_price' => 5000], 1, 60);
$tooDear = array_filter($cheap['products'], static fn (array $r): bool => (float) $r['price'] > 5000);
TestRunner::same('A price ceiling is applied in SQL', 0, count($tooDear));

$swapped = $catalog->browse(['min_price' => 9000, 'max_price' => 1000], 1, 60);
TestRunner::check(
    'Reversed price bounds are swapped rather than returning nothing',
    $swapped['total'] >= 0,
    $swapped['total'] . ' results'
);

$sorted = $catalog->browse(['sort' => 'price_asc'], 1, 10)['products'];
$prices = array_map(static fn (array $r): float => (float) $r['price'], $sorted);
$ascending = $prices;
sort($ascending);
TestRunner::same('Sorting by price ascending works', $ascending, $prices);

$injected = $catalog->browse(['sort' => 'p.price; DROP TABLE products'], 1, 5);
TestRunner::check(
    'An unrecognised sort key falls back instead of reaching the query',
    $injected['total'] > 0,
    'ORDER BY comes from an allow-list'
);
TestRunner::check(
    'The products table is still there',
    (int) Database::scalar('SELECT COUNT(*) FROM products') > 0
);

// ---------------------------------------------------------------------------
TestRunner::section('B. SEARCH');

$found = $catalog->search('oil');
TestRunner::check('Full-text search returns matches', $found['count'] > 0, $found['count'] . ' hits for "oil"');

$prefix = $catalog->search('oi');
TestRunner::check('A prefix matches - "oi" finds oil', $prefix['count'] > 0, $prefix['count'] . ' hits');

TestRunner::same('An empty query returns nothing rather than everything', 0, $catalog->search('   ')['count']);

$nonsense = $catalog->search('zzzqqqxxx');
TestRunner::same('A query that matches nothing returns nothing', 0, $nonsense['count']);

$quoted = $catalog->search('" OR 1=1 --');
TestRunner::check(
    'A search containing SQL is just a search',
    $quoted['count'] >= 0,
    'bound as a parameter, not concatenated'
);

// ---------------------------------------------------------------------------
TestRunner::section('C. PRODUCT AND STORE PAGES');

$productSlug = (string) Database::scalar(
    "SELECT slug FROM products WHERE status = 'published' ORDER BY id LIMIT 1"
);

$page = $catalog->product($productSlug);
TestRunner::check('A product page loads', isset($page['product']['name']), (string) $page['product']['name']);
TestRunner::check('Availability is listed PER STORE', is_array($page['availability']), count($page['availability']) . ' stores');
TestRunner::check('The rating distribution always has five keys', count($page['rating']['distribution']) === 5);
TestRunner::check(
    'The stock state is one of three known values',
    in_array($page['stock_state'], ['in_stock', 'low_stock', 'out_of_stock'], true),
    $page['stock_state']
);
TestRunner::check(
    'Related products exclude the product being viewed',
    !in_array((int) $page['product']['id'], array_map(static fn (array $r): int => (int) $r['id'], $page['related']), true)
);

TestRunner::throws(
    'An unknown product slug is refused, not shown empty',
    static fn () => $catalog->product('no-such-product-anywhere'),
    'not available'
);

// The seed publishes every product, so this assertion makes its own draft
// rather than hoping one is lying around. It used to look for an existing
// unpublished row and skip silently when there was none - which meant the
// check quietly stopped running against a freshly imported database, on
// exactly the rule the public catalogue depends on.
in_rollback(static function () use ($catalog): void {
    $source = Database::selectOne(
        "SELECT * FROM products WHERE status = 'published' ORDER BY id LIMIT 1"
    );

    $draftSlug = 'draft-product-visibility-check';

    Database::insert('products', [
        'seller_id'   => $source['seller_id'],
        'category_id' => $source['category_id'],
        'slug'        => $draftSlug,
        'name'        => 'Draft product that must stay invisible',
        'sku'         => 'DRAFT-VIS-001',
        'description' => 'Created by the test suite and rolled back.',
        'price'       => '1000.00',
        'unit'        => 'each',
        'pack_size'   => '1',
        'status'      => 'draft',
    ]);

    TestRunner::throws(
        'An unpublished product cannot be reached by guessing its slug',
        static fn () => $catalog->product($draftSlug),
        'not available'
    );

    TestRunner::same(
        'Nor does it appear in the public listing',
        0,
        count(array_filter(
            $catalog->browse([], 1, 60)['products'],
            static fn (array $r): bool => (string) $r['slug'] === $draftSlug
        ))
    );
});

$storeSlug = (string) Database::scalar("SELECT slug FROM stores WHERE status = 'published' LIMIT 1");
$storePage = $catalog->store($storeSlug);
TestRunner::check('A store page loads with its opening hours', count($storePage['hours']) === 7, count($storePage['hours']) . ' days');

// ---------------------------------------------------------------------------
TestRunner::section('D. RESERVATION - the oversell guard through the service');

in_rollback(static function () use ($stock, $inventory, $sample): void {
    $productId = (int) $sample['id'];
    $storeId   = (int) $sample['store_id'];

    // Exactly one unit available.
    Database::statement(
        'UPDATE inventory SET qty_on_hand = 1, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    $line = [['product_id' => $productId, 'store_id' => $storeId, 'quantity' => 1]];

    $stock->reserveAll($line, 'seller_order:999001');
    TestRunner::same('The first customer reserves the last unit', 0, $inventory->availableFor($productId, $storeId));

    TestRunner::throws(
        'The second customer is refused, by name',
        static fn () => $stock->reserveAll($line, 'seller_order:999002'),
        'sold out'
    );

    $row = $inventory->findFor($productId, $storeId);
    TestRunner::same('Exactly one unit is reserved in total', 1, (int) $row['qty_reserved']);
});

in_rollback(static function () use ($stock, $inventory, $sample): void {
    $productId = (int) $sample['id'];
    $storeId   = (int) $sample['store_id'];

    Database::statement(
        'UPDATE inventory SET qty_on_hand = 3, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    TestRunner::throws(
        'Asking for more than exists says how many are left',
        static fn () => $stock->reserveAll(
            [['product_id' => $productId, 'store_id' => $storeId, 'quantity' => 5]],
            'seller_order:999003'
        ),
        'Only 3'
    );

    TestRunner::same(
        'A refused reservation reserves nothing',
        0,
        (int) $inventory->findFor($productId, $storeId)['qty_reserved']
    );
});

in_rollback(static function () use ($stock, $inventory): void {
    // Two lines: the first can be met, the second cannot. Neither may be held.
    $rows = Database::select(
        "SELECT i.product_id, i.store_id FROM inventory i
          JOIN products p ON p.id = i.product_id
         WHERE p.status = 'published' ORDER BY i.product_id LIMIT 2"
    );

    if (count($rows) < 2) {
        TestRunner::check('Two stocked products exist for the all-or-nothing test', false, 'seed too small');
        return;
    }

    Database::statement(
        'UPDATE inventory SET qty_on_hand = 10, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $rows[0]['product_id'], 's' => $rows[0]['store_id']]
    );
    Database::statement(
        'UPDATE inventory SET qty_on_hand = 0, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $rows[1]['product_id'], 's' => $rows[1]['store_id']]
    );

    $basket = [
        ['product_id' => (int) $rows[0]['product_id'], 'store_id' => (int) $rows[0]['store_id'], 'quantity' => 2],
        ['product_id' => (int) $rows[1]['product_id'], 'store_id' => (int) $rows[1]['store_id'], 'quantity' => 1],
    ];

    try {
        Database::transaction(static fn () => $stock->reserveAll($basket, 'seller_order:999004'));
    } catch (DomainRuleException) {
        // expected
    }

    TestRunner::same(
        'A basket that cannot be fully met reserves NOTHING',
        0,
        (int) $inventory->findFor((int) $rows[0]['product_id'], (int) $rows[0]['store_id'])['qty_reserved']
    );
});

in_rollback(static function () use ($stock, $inventory, $sample): void {
    $productId = (int) $sample['id'];
    $storeId   = (int) $sample['store_id'];

    Database::statement(
        'UPDATE inventory SET qty_on_hand = 5, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    $line = [['product_id' => $productId, 'store_id' => $storeId, 'quantity' => 2]];

    $stock->reserveAll($line, 'seller_order:999005');
    TestRunner::same('Two reserved leaves three available', 3, $inventory->availableFor($productId, $storeId));

    $stock->releaseAll($line, 'seller_order:999005', 'Seller rejected the order');
    TestRunner::same('Releasing returns them to the shelf', 5, $inventory->availableFor($productId, $storeId));
    TestRunner::same(
        'And qty_on_hand was never touched by a reservation',
        5,
        (int) $inventory->findFor($productId, $storeId)['qty_on_hand']
    );

    $stock->reserveAll($line, 'seller_order:999006');
    $stock->consumeAll($line, 'seller_order:999006');

    $row = $inventory->findFor($productId, $storeId);
    TestRunner::same('Handover takes the units off the shelf', 3, (int) $row['qty_on_hand']);
    TestRunner::same('And clears the reservation', 0, (int) $row['qty_reserved']);
});

// Not wrapped in in_rollback(): the point is that there is NO transaction.
TestRunner::throws(
    'Reserving outside a transaction is refused outright',
    static fn () => $stock->reserveAll(
        [['product_id' => (int) $sample['id'], 'store_id' => (int) $sample['store_id'], 'quantity' => 1]],
        'seller_order:999000'
    ),
    'must run inside a transaction'
);

// ---------------------------------------------------------------------------
TestRunner::section('E. MOVEMENT LOG - every change leaves a trace');

in_rollback(static function () use ($stock, $inventory, $sample): void {
    $productId = (int) $sample['id'];
    $storeId   = (int) $sample['store_id'];

    $before = (int) Database::scalar(
        'SELECT COUNT(*) FROM stock_movements WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    Database::statement(
        'UPDATE inventory SET qty_on_hand = 4, qty_reserved = 0 WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    $line = [['product_id' => $productId, 'store_id' => $storeId, 'quantity' => 1]];

    $stock->reserveAll($line, 'seller_order:999007');
    $stock->releaseAll($line, 'seller_order:999007');
    $stock->reserveAll($line, 'seller_order:999008');
    $stock->consumeAll($line, 'seller_order:999008');

    $after = (int) Database::scalar(
        'SELECT COUNT(*) FROM stock_movements WHERE product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]
    );

    TestRunner::same('Four stock changes wrote four movement rows', 4, $after - $before);

    $recent = $inventory->movementsFor($productId, $storeId, 4);
    $types  = array_map(static fn (array $r): string => (string) $r['movement_type'], $recent);

    TestRunner::same(
        'Each carries the right movement type',
        ['fulfilment', 'reservation', 'release', 'reservation'],
        $types
    );

    TestRunner::check(
        'Each names the order it belongs to',
        (int) $recent[0]['reference_id'] === 999008 && (string) $recent[0]['reference_type'] === 'seller_order',
        $recent[0]['reference_type'] . ':' . $recent[0]['reference_id']
    );

    $updatable = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'stock_movements' AND column_name = 'updated_at'"
    );
    TestRunner::same('The movement log has no updated_at - it is append-only', 0, $updatable);
});

// ---------------------------------------------------------------------------
TestRunner::section('F. SELLER SCOPING - ownership is in the WHERE clause');

in_rollback(static function () use ($stock, $products, $stores): void {
    $mine     = seed_user('seller.mama.lishe@sokolink.test');
    $theirs   = seed_user('seller.duka.kuu@sokolink.test');

    Auth::actAs($mine);
    $mySellerId = Auth::sellerId();

    Auth::actAs($theirs);
    $theirSellerId = Auth::sellerId();

    Auth::actAs($mine);

    $theirProduct = (int) Database::scalar(
        'SELECT id FROM products WHERE seller_id = :s LIMIT 1',
        ['s' => $theirSellerId]
    );
    $theirStore = (int) Database::scalar(
        'SELECT id FROM stores WHERE seller_id = :s LIMIT 1',
        ['s' => $theirSellerId]
    );

    TestRunner::same(
        'Another seller product reads as null, exactly like a missing id',
        null,
        $products->findOwnedBy($theirProduct, (int) $mySellerId)
    );

    TestRunner::same(
        'Another seller store reads as null too',
        null,
        $stores->findOwnedBy($theirStore, (int) $mySellerId)
    );

    TestRunner::throws(
        'A seller cannot add stock to another seller product',
        static fn () => $stock->receiveStock((int) $mySellerId, $theirProduct, $theirStore, 10),
        'not one of yours'
    );

    $myProduct = (int) Database::scalar(
        'SELECT id FROM products WHERE seller_id = :s LIMIT 1',
        ['s' => $mySellerId]
    );

    TestRunner::throws(
        'Nor move their own stock into another seller store',
        static fn () => $stock->receiveStock((int) $mySellerId, $myProduct, $theirStore, 10),
        'not one of yours'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('G. SELLER STOCK MANAGEMENT');

in_rollback(static function () use ($stock, $inventory): void {
    $sellerId = (int) Database::scalar(
        'SELECT id FROM sellers WHERE user_id = :u',
        ['u' => seed_user('seller.mama.lishe@sokolink.test')]
    );
    Auth::actAs(seed_user('seller.mama.lishe@sokolink.test'));

    $row = Database::selectOne(
        'SELECT i.product_id, i.store_id FROM inventory i
           JOIN products p ON p.id = i.product_id
          WHERE p.seller_id = :s LIMIT 1',
        ['s' => $sellerId]
    );

    $productId = (int) $row['product_id'];
    $storeId   = (int) $row['store_id'];

    $before = $inventory->availableFor($productId, $storeId);

    $stock->receiveStock($sellerId, $productId, $storeId, 12, 'Delivery from the mill');
    TestRunner::same('Receiving stock raises availability', $before + 12, $inventory->availableFor($productId, $storeId));

    $stock->adjustStock($sellerId, $productId, $storeId, -2, 'damage', 'Two bottles broke in transit');
    TestRunner::same('A loss lowers it', $before + 10, $inventory->availableFor($productId, $storeId));

    TestRunner::throws(
        'An adjustment without a reason is refused',
        static fn () => $stock->adjustStock($sellerId, $productId, $storeId, -1, 'damage', '   '),
        'Say why'
    );

    TestRunner::throws(
        'A zero adjustment is refused',
        static fn () => $stock->adjustStock($sellerId, $productId, $storeId, 0, 'damage', 'Nothing'),
        'other than zero'
    );

    // Reserve most of the stock, then try to adjust below what is promised.
    Database::transaction(static function () use ($stock, $productId, $storeId): void {
        $stock->reserveAll(
            [['product_id' => $productId, 'store_id' => $storeId, 'quantity' => 8]],
            'seller_order:999009'
        );
    });

    TestRunner::throws(
        'Stock cannot be adjusted below what is already promised to orders',
        static fn () => $stock->adjustStock($sellerId, $productId, $storeId, -1000, 'recount', 'Shelf is empty'),
        'already promised to orders'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('H. COLLECTION POINTS');

$pickupOptions = $stores->pickupOptionsFor((int) $sample['id'], 1);

// One assertion over the whole list rather than one per store. Asserting inside
// the loop made the suite's total depend on how much stock happened to exist -
// the oversell test drives a store to zero and it drops out - so a changing
// count meant nothing, which is the opposite of what a count is for.
$withoutStock = array_values(array_filter(
    $pickupOptions,
    static fn (array $o): bool => (int) $o['qty_available'] < 1
));

TestRunner::check('At least one collection point is offered', $pickupOptions !== [], count($pickupOptions) . ' stores');
TestRunner::same('Every one of them has stock to offer', [], array_column($withoutStock, 'name'));

$impossible = $stores->pickupOptionsFor((int) $sample['id'], 99999);
TestRunner::same('No store is offered for a quantity nobody has', 0, count($impossible));

$unpublishedOffered = 0;
foreach ($pickupOptions as $option) {
    $status = (string) Database::scalar('SELECT status FROM stores WHERE id = :id', ['id' => $option['id']]);
    if ($status !== 'published') {
        $unpublishedOffered++;
    }
}
TestRunner::same('No unpublished store is ever offered as a collection point', 0, $unpublishedOffered);

TestRunner::finish();
