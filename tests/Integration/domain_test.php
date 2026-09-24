<?php

declare(strict_types=1);

/**
 * Phase 3.2 - the domain layer.
 *
 * Two things are checked here, and they are the two things that go wrong with
 * a hand-maintained domain model:
 *
 *   A. The PHP enums and the database ENUM columns agree. They were generated
 *      from the schema, so they agree today. This test is about tomorrow - the
 *      migration that adds a status to one and forgets the other.
 *
 *   B. The order state machine refuses what it should refuse. The brief is
 *      specific that arbitrary users must not be able to mark orders collected,
 *      so the interesting assertions here are the negative ones.
 *
 * Run: php tests/Integration/domain_test.php
 */

use App\Core\Database;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\FulfilmentMethod;
use App\Domain\Enums\OrderStatus;
use App\Domain\OrderStateMachine;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Domain - enum parity and the order state machine');

// ---------------------------------------------------------------------------
TestRunner::section('A. ENUM PARITY - PHP cases against the database columns');

/** class => [table, column] */
$pairs = [
    'UserStatus'              => ['users', 'status'],
    'TokenPurpose'            => ['user_tokens', 'purpose'],
    'ConsentType'             => ['consent_records', 'consent_type'],
    'SellerStatus'            => ['sellers', 'status'],
    'SellerApplicationStatus' => ['seller_applications', 'status'],
    'StoreStatus'             => ['stores', 'status'],
    'ProductStatus'           => ['products', 'status'],
    'CartStatus'              => ['carts', 'status'],
    'FulfilmentMethod'        => ['seller_orders', 'fulfilment_method'],
    'OrderStatus'             => ['seller_orders', 'status'],
    'PaymentStatus'           => ['orders', 'payment_status'],
    'PaymentMethod'           => ['orders', 'payment_method'],
    'ActorType'               => ['order_status_history', 'actor_type'],
    'DeliveryTaskStatus'      => ['delivery_tasks', 'status'],
    'DeliveryEventType'       => ['delivery_events', 'event_type'],
    'DeliveryFailureReason'   => ['delivery_events', 'reason_code'],
    'PaymentIntentStatus'     => ['payment_intents', 'status'],
    'TransactionDirection'    => ['payment_transactions', 'direction'],
    'TransactionStatus'       => ['payment_transactions', 'status'],
    'RefundStatus'            => ['refunds', 'status'],
    'StockMovementType'       => ['stock_movements', 'movement_type'],
    'NotificationChannel'     => ['notifications', 'channel'],
    'NotificationCategory'    => ['notifications', 'category'],
    'NotificationStatus'      => ['notifications', 'status'],
    'SkipReason'              => ['reorder_reminders', 'skip_reason'],
    'ReminderBasis'           => ['reorder_reminders', 'basis'],
    'ReminderState'           => ['reorder_reminders', 'state'],
    'ReviewStatus'            => ['reviews', 'status'],
    'TicketStatus'            => ['support_tickets', 'status'],
    'TicketPriority'          => ['support_tickets', 'priority'],
    'TicketCategory'          => ['support_tickets', 'category'],
    'MessageAuthorRole'       => ['support_messages', 'author_role'],
];

$mismatched = 0;

foreach ($pairs as $short => [$table, $column]) {
    $class = 'App\\Domain\\Enums\\' . $short;

    $type = (string) Database::scalar(
        'SELECT column_type FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $table, 'c' => $column]
    );

    preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
    $dbValues  = array_map(static fn (string $v): string => str_replace("''", "'", $v), $m[1]);
    $phpValues = $class::values();

    sort($dbValues);
    sort($phpValues);

    if ($dbValues !== $phpValues) {
        $mismatched++;
        TestRunner::check(
            sprintf('%s matches %s.%s', $short, $table, $column),
            false,
            'db-only: ' . implode(',', array_diff($dbValues, $phpValues))
            . ' php-only: ' . implode(',', array_diff($phpValues, $dbValues))
        );
    }
}

TestRunner::same('Every mirrored enum matches its column', 0, $mismatched);
TestRunner::same('All 32 mirrored columns were checked', 32, count($pairs));

// Labels exist and are not just the raw value echoed back.
$rawLabels = 0;
foreach (OrderStatus::cases() as $case) {
    if ($case->label() === $case->value) {
        $rawLabels++;
    }
}
TestRunner::same('No order status shows its raw database value to a user', 0, $rawLabels);

// ---------------------------------------------------------------------------
TestRunner::section('B. STATE MACHINE - the happy paths');

$pickupPath = [
    ['pending_payment', 'awaiting_seller', 'system'],
    ['awaiting_seller', 'confirmed',        'seller'],
    ['confirmed',       'preparing',        'seller'],
    ['preparing',       'ready_for_pickup', 'seller'],
    ['ready_for_pickup', 'collected',       'seller'],
    ['collected',       'completed',        'system'],
];

foreach ($pickupPath as [$from, $to, $actor]) {
    TestRunner::doesNotThrow(
        sprintf('Pickup: %s -> %s by %s', $from, $to, $actor),
        static fn () => OrderStateMachine::assertCan(
            OrderStatus::from($from),
            OrderStatus::from($to),
            FulfilmentMethod::Pickup,
            ActorType::from($actor)
        )
    );
}

$deliveryPath = [
    ['preparing',          'ready_for_dispatch', 'seller'],
    ['ready_for_dispatch', 'assigned',           'admin'],
    ['assigned',           'picked_up',          'agent'],
    ['picked_up',          'out_for_delivery',   'agent'],
    ['out_for_delivery',   'delivered',          'agent'],
];

foreach ($deliveryPath as [$from, $to, $actor]) {
    TestRunner::doesNotThrow(
        sprintf('Delivery: %s -> %s by %s', $from, $to, $actor),
        static fn () => OrderStateMachine::assertCan(
            OrderStatus::from($from),
            OrderStatus::from($to),
            FulfilmentMethod::Delivery,
            ActorType::from($actor)
        )
    );
}

// ---------------------------------------------------------------------------
TestRunner::section('C. STATE MACHINE - what it refuses');

TestRunner::throws(
    'A customer cannot mark their own order collected',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::ReadyForPickup,
        OrderStatus::Collected,
        FulfilmentMethod::Pickup,
        ActorType::Customer
    ),
    'cannot mark an order'
);

TestRunner::throws(
    'A delivery agent cannot mark an order collected in a shop',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::ReadyForPickup,
        OrderStatus::Collected,
        FulfilmentMethod::Pickup,
        ActorType::Agent
    ),
    'cannot mark an order'
);

TestRunner::throws(
    'A seller cannot mark a delivery order delivered',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
        FulfilmentMethod::Delivery,
        ActorType::Seller
    ),
    'cannot mark an order'
);

TestRunner::throws(
    'A pickup order cannot go out for delivery',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::Preparing,
        OrderStatus::ReadyForDispatch,
        FulfilmentMethod::Pickup,
        ActorType::Seller
    ),
    'applies to delivery orders'
);

TestRunner::throws(
    'A delivery order cannot become ready for collection',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::Preparing,
        OrderStatus::ReadyForPickup,
        FulfilmentMethod::Delivery,
        ActorType::Seller
    ),
    'applies to pickup orders'
);

TestRunner::throws(
    'An order cannot skip from awaiting_seller straight to collected',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::AwaitingSeller,
        OrderStatus::Collected,
        FulfilmentMethod::Pickup,
        ActorType::Seller
    ),
    'cannot become'
);

TestRunner::throws(
    'A completed order cannot be changed at all',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::Completed,
        OrderStatus::Preparing,
        FulfilmentMethod::Pickup,
        ActorType::Admin
    ),
    'cannot be changed further'
);

TestRunner::throws(
    'A refunded order cannot be un-refunded',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::Refunded,
        OrderStatus::Completed,
        FulfilmentMethod::Pickup,
        ActorType::Admin
    ),
    'cannot be changed further'
);

TestRunner::throws(
    'A seller cannot cancel on the customer behalf - that is a rejection',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::AwaitingSeller,
        OrderStatus::CancelledCustomer,
        FulfilmentMethod::Pickup,
        ActorType::Seller
    ),
    'cannot mark an order'
);

TestRunner::throws(
    'Setting a status to itself is refused rather than silently accepted',
    static fn () => OrderStateMachine::assertCan(
        OrderStatus::Preparing,
        OrderStatus::Preparing,
        FulfilmentMethod::Pickup,
        ActorType::Seller
    ),
    'already'
);

// ---------------------------------------------------------------------------
TestRunner::section('D. STATE MACHINE - completeness and stock rules');

$declared = array_keys(OrderStateMachine::table());
$enumVals = OrderStatus::values();

sort($declared);
sort($enumVals);

TestRunner::same('Every status has a row in the transition table', $enumVals, $declared);

$unreachableTargets = [];
foreach (OrderStateMachine::table() as $from => $targets) {
    foreach ($targets as $target) {
        if (OrderStatus::tryFrom($target) === null) {
            $unreachableTargets[] = $from . ' -> ' . $target;
        }
    }
}
TestRunner::same('No transition points at a status that does not exist', [], $unreachableTargets);

$noActor = [];
foreach (OrderStateMachine::table() as $targets) {
    foreach ($targets as $target) {
        if ((OrderStateMachine::actorTable()[$target] ?? []) === []) {
            $noActor[] = $target;
        }
    }
}
TestRunner::same('Every reachable status names who may set it', [], array_values(array_unique($noActor)));

TestRunner::check(
    'Collection consumes stock rather than releasing it',
    OrderStateMachine::consumesStock(OrderStatus::Collected)
        && !OrderStateMachine::releasesStock(OrderStatus::Collected),
    'units that left the shop must not return to the shelf'
);

TestRunner::check(
    'A seller rejection releases the reservation',
    OrderStateMachine::releasesStock(OrderStatus::RejectedSeller)
        && OrderStateMachine::requiresRefund(OrderStatus::RejectedSeller),
    'stock back on the shelf, money back to the customer'
);

TestRunner::check(
    'An unpaid expiry releases the reservation',
    OrderStateMachine::releasesStock(OrderStatus::ExpiredUnpaid),
    'otherwise an abandoned basket holds stock forever'
);

TestRunner::check(
    'A rejection must carry a reason',
    OrderStateMachine::requiresReason(OrderStatus::RejectedSeller)
        && OrderStateMachine::requiresReason(OrderStatus::DeliveryFailed),
    'telling a customer no without saying why is not acceptable'
);

$sellerNext = OrderStateMachine::nextFor(OrderStatus::Preparing, FulfilmentMethod::Pickup, ActorType::Seller);
TestRunner::same(
    'A seller preparing a pickup order is offered exactly two next steps',
    ['ready_for_pickup', 'rejected_seller'],
    array_map(static fn (OrderStatus $s): string => $s->value, $sellerNext)
);

$customerNext = OrderStateMachine::nextFor(OrderStatus::ReadyForPickup, FulfilmentMethod::Pickup, ActorType::Customer);
TestRunner::same(
    'A customer whose order is ready may only cancel it',
    ['cancelled_customer'],
    array_map(static fn (OrderStatus $s): string => $s->value, $customerNext)
);

TestRunner::finish();
