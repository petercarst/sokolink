<?php

declare(strict_types=1);

/**
 * Phase 4d - support and retention, driven through the HTTP layer.
 *
 * Two features share this file because they share a boundary: both decide what
 * one person is allowed to know about another.
 *
 * What this proves, which no service test can:
 *
 *   - An internal note is absent from the customer's thread because the QUERY
 *     does not fetch it. The assertion below reads the returned rows, not the
 *     rendered page, so it fails even if a template happens to hide one.
 *   - A customer cannot open, reply to, or attach an order to somebody else's
 *     ticket, and "not yours" looks exactly like "does not exist".
 *   - Support can open any order - that is the job - and every open leaves an
 *     audit row naming the ticket that justified it, including one that found
 *     nothing.
 *   - Support can reissue a collection code and still cannot read one.
 *   - Consent follows the toggles, queued marketing is cancelled when somebody
 *     unsubscribes, and a category that is not the customer's to switch off
 *     survives a crafted POST that tries.
 *   - Reorder re-checks price and stock and SAYS what changed, rather than
 *     adding last month's basket at last month's price.
 *
 * Run: php tests/Http/support_workflow_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Repositories\ConsentRepository;
use App\Repositories\SupportRepository;
use App\Services\ReorderService;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/kernel.php';

const SEED_PASSWORD = 'SokoLink!Dev2026';
const TEST_IP       = '127.0.0.1';

TestRunner::suite('Phase 4d - support and retention over HTTP');

Http::boot();

/** Every reference this run creates, removed at the end whatever happened. */
$madeTickets = [];

/**
 * High-water marks for the two APPEND-ONLY tables.
 *
 * `consent_records` and `audit_log` are never updated in place - that is the
 * property that makes them worth having, and this suite asserts it. So they
 * cannot be tidied by matching on content: the teardown removes everything
 * written after this point and nothing written before it, which is exactly the
 * set this run is responsible for.
 */
$highWater = [
    'consent_records' => (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM consent_records'),
    'audit_log'       => (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_log'),
];

function as_support(): int
{
    Http::newVisitor();
    Http::post('/login', ['email' => 'support@sokolink.test', 'password' => SEED_PASSWORD]);

    return (int) Auth::id();
}

function as_customer(string $email = 'customer.asha@sokolink.test'): int
{
    Http::newVisitor();
    Http::post('/login', ['email' => $email, 'password' => SEED_PASSWORD]);

    return (int) Auth::id();
}

function ticket_id(string $ref): int
{
    return (int) Database::scalar('SELECT id FROM support_tickets WHERE ticket_ref = :r', ['r' => $ref]);
}

/**
 * Opens a ticket through the real form and returns its reference.
 *
 * The reference is read out of the flash rather than out of the database,
 * which also proves the handler told the customer what it created.
 *
 * @param array<string,mixed> $overrides
 */
function open_ticket(array $overrides = []): string
{
    Http::post('/customer/support/open', array_merge([
        'subject'  => 'Test request ' . bin2hex(random_bytes(3)),
        'category' => 'other',
        'body'     => 'Something went wrong and I would like it looked at please.',
    ], $overrides));

    preg_match('/TKT-[A-Z0-9-]+/', Http::flashText(), $m);

    return $m[0] ?? '';
}

// =============================================================================
TestRunner::section('A. A customer opens a request');

$customerId = as_customer();

$ref = open_ticket(['subject' => 'Collection code never arrived', 'category' => 'collection']);
$madeTickets[] = $ref;

TestRunner::check('Opening a request returns a reference', $ref !== '', Http::flashText());

TestRunner::same(
    'It is stored against that customer, open, with their first message',
    ['user' => $customerId, 'status' => 'open', 'messages' => 1],
    [
        'user'     => (int) Database::scalar('SELECT user_id FROM support_tickets WHERE ticket_ref = :r', ['r' => $ref]),
        'status'   => (string) Database::scalar('SELECT status FROM support_tickets WHERE ticket_ref = :r', ['r' => $ref]),
        'messages' => (int) Database::scalar(
            'SELECT COUNT(*) FROM support_messages WHERE ticket_id = :t',
            ['t' => ticket_id($ref)]
        ),
    ]
);

TestRunner::same('Opening it is audited', 1, (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'support.ticket.opened' AND entity_id = :id",
    ['id' => ticket_id($ref)]
));

Http::post('/customer/support/open', ['subject' => '', 'category' => 'other', 'body' => '']);
TestRunner::check(
    'An empty form comes back with per-field errors, not a fault',
    array_key_exists('subject', Http::errors()) && array_key_exists('body', Http::errors()),
    implode(', ', array_keys(Http::errors()))
);

$foreignOrder = (string) Database::scalar(
    'SELECT o.order_number FROM orders o WHERE o.user_id <> :u LIMIT 1',
    ['u' => $customerId]
);
Http::post('/customer/support/open', [
    'subject'      => 'About an order',
    'category'     => 'order',
    'body'         => 'I would like to ask about this order please.',
    'order_number' => $foreignOrder,
]);
TestRunner::check(
    "Another customer's order reference is refused",
    array_key_exists('order_number', Http::errors()),
    Http::flashText()
);

$ownOrder = (string) Database::scalar(
    'SELECT o.order_number FROM orders o WHERE o.user_id = :u LIMIT 1',
    ['u' => $customerId]
);
$withOrder = open_ticket([
    'subject'      => 'Where has my order got to',
    'category'     => 'order',
    'body'         => 'It has been a while and I have not heard anything.',
    'order_number' => $ownOrder,
]);
$madeTickets[] = $withOrder;

TestRunner::same(
    'Their own order reference is accepted and attached',
    1,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM support_tickets t JOIN orders o ON o.id = t.order_id
          WHERE t.ticket_ref = :r AND o.order_number = :n',
        ['r' => $withOrder, 'n' => $ownOrder]
    )
);

// =============================================================================
TestRunner::section('B. A thread belongs to one customer');

TestRunner::same('Their own thread renders', 200, Http::get('/customer/support/' . $ref)->status());

$foreignRef = (string) Database::scalar(
    'SELECT ticket_ref FROM support_tickets WHERE user_id <> :u LIMIT 1',
    ['u' => $customerId]
);

TestRunner::same(
    "Somebody else's reference is a 404, not a 403",
    404,
    Http::get('/customer/support/' . $foreignRef)->status()
);

TestRunner::same(
    'A reference that never existed is the same 404',
    404,
    Http::get('/customer/support/TKT-00-NOPE00')->status()
);

Http::post('/customer/support/reply', ['ref' => $foreignRef, 'body' => 'Let me into this thread.']);
TestRunner::same(
    "Replying to somebody else's ticket adds nothing",
    0,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM support_messages WHERE ticket_id = :t AND body = :b',
        ['t' => ticket_id($foreignRef), 'b' => 'Let me into this thread.']
    )
);

TestRunner::same(
    'And lands on their own list rather than a page that would 404',
    '/customer/support',
    Http::redirectedTo(Http::post('/customer/support/reply', ['ref' => $foreignRef, 'body' => 'Again.']))
);

// =============================================================================
TestRunner::section('C. An internal note never reaches the customer');

$supportId = as_support();
$ticketId  = ticket_id($ref);

$statusBeforeNote = (string) Database::scalar(
    'SELECT status FROM support_tickets WHERE id = :t',
    ['t' => $ticketId]
);

Http::post('/support/tickets/reply', [
    'ref'      => $ref,
    'body'     => 'NOTE-TO-SELF: this one has complained three times this month.',
    'internal' => '1',
]);

TestRunner::same(
    'An internal note is stored as internal',
    1,
    (int) Database::scalar(
        'SELECT is_internal FROM support_messages WHERE ticket_id = :t ORDER BY id DESC LIMIT 1',
        ['t' => $ticketId]
    )
);

// An internal note is not something the customer is waiting on, because they
// never see it. Asserted as "unchanged" rather than as a named status, so the
// check does not quietly become a tautology if the queue behaviour moves.
TestRunner::same(
    'An internal note leaves the status alone - the customer is not waiting on anything',
    $statusBeforeNote,
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId])
);

$repo  = new SupportRepository();
$staff = $repo->staffMessages($ticketId);
$cust  = $repo->customerMessages($ticketId, $customerId);

TestRunner::same('Staff see the note', 1, count(array_filter(
    $staff,
    static fn (array $m): bool => str_contains((string) $m['body'], 'NOTE-TO-SELF')
)));

// The important one. This reads the ROWS, not the page: a template that hid
// the note would still fail here, which is the point.
TestRunner::same(
    'The customer query does not return it at all',
    0,
    count(array_filter(
        $cust,
        static fn (array $m): bool => str_contains((string) $m['body'], 'NOTE-TO-SELF')
    ))
);

TestRunner::same(
    'No row the customer query returns is marked internal',
    [],
    array_values(array_filter($cust, static fn (array $m): bool => !empty($m['is_internal'])))
);

as_customer();
$page = Http::get('/customer/support/' . $ref);
TestRunner::check(
    'And the rendered page does not contain it either',
    !Http::sees($page, 'NOTE-TO-SELF'),
    'leaked into the HTML'
);

// =============================================================================
TestRunner::section('D. A staff reply is a reply');

as_support();

$before = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE reference_type = :t AND reference_id = :i',
    ['t' => 'support_ticket', 'i' => $ticketId]
);

Http::post('/support/tickets/reply', ['ref' => $ref, 'body' => 'We have reissued your code - sorry about that.']);

TestRunner::same(
    'A reply the customer can read moves the ticket to waiting on them',
    'waiting_customer',
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId])
);

TestRunner::same(
    'And queues them a message, which the internal note did not',
    $before + 1,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM notifications WHERE reference_type = :t AND reference_id = :i',
        ['t' => 'support_ticket', 'i' => $ticketId]
    )
);

Http::post('/support/tickets/reply', ['ref' => $ref, 'body' => '']);
TestRunner::check(
    'An empty reply is refused with a message, not a 500',
    str_contains(Http::flashText(), 'Write the reply'),
    Http::flashText()
);

// Assigning is a no-op when it is already theirs, and a no-op is not an error:
// MySQL reports zero CHANGED rows, which must not read as "no such ticket".
Http::post('/support/tickets/assign', ['ref' => $ref]);
Http::post('/support/tickets/assign', ['ref' => $ref]);
TestRunner::check(
    'Assigning a ticket already assigned to you is not "could not be found"',
    !str_contains(Http::flashText(), 'could not be found'),
    Http::flashText()
);

TestRunner::same(
    'It is assigned to the agent who took it',
    $supportId,
    (int) Database::scalar('SELECT assigned_to FROM support_tickets WHERE id = :t', ['t' => $ticketId])
);

// =============================================================================
TestRunner::section('E. A customer reply puts it back on the queue');

as_customer();
Http::post('/customer/support/reply', ['ref' => $ref, 'body' => 'Thank you, the new code worked.']);

TestRunner::same(
    'Replying reopens it rather than leaving it waiting on them',
    'open',
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId])
);

// =============================================================================
TestRunner::section('F. Resolving and escalating both need a reason');

as_support();

Http::post('/support/tickets/resolve', ['ref' => $ref, 'summary' => '']);
TestRunner::check(
    'Resolving with no summary is refused',
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId]) !== 'resolved',
    Http::flashText()
);

Http::post('/support/tickets/escalate', ['ref' => $ref, 'reason' => '']);
TestRunner::check(
    'Escalating with no reason is refused',
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId]) !== 'escalated',
    Http::flashText()
);

Http::post('/support/tickets/escalate', ['ref' => $ref, 'reason' => 'Refund is outside the normal window.']);

$escalated = Database::selectOne(
    'SELECT status, escalated_to, escalation_reason, priority FROM support_tickets WHERE id = :t',
    ['t' => $ticketId]
);

TestRunner::same(
    'An escalation records the reason, an administrator and a raised priority',
    ['status' => 'escalated', 'priority' => 'high', 'hasAdmin' => true, 'reason' => 'Refund is outside the normal window.'],
    [
        'status'   => (string) $escalated['status'],
        'priority' => (string) $escalated['priority'],
        'hasAdmin' => $escalated['escalated_to'] !== null,
        'reason'   => (string) $escalated['escalation_reason'],
    ]
);

TestRunner::same('The escalation is audited as a sensitive action', 1, (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'support.ticket.escalated' AND entity_id = :id",
    ['id' => $ticketId]
));

TestRunner::check(
    'The reason is on the escalations queue for whoever picks it up',
    Http::sees(Http::get('/support/escalations'), 'Refund is outside the normal window.')
);

Http::post('/support/tickets/resolve', ['ref' => $ref, 'summary' => 'Code reissued and the collection went through.']);

TestRunner::same(
    'Resolving with a summary works',
    'resolved',
    (string) Database::scalar('SELECT status FROM support_tickets WHERE id = :t', ['t' => $ticketId])
);

TestRunner::same(
    'And the summary is added to the thread, so the customer reads it too',
    1,
    (int) Database::scalar(
        'SELECT COUNT(*) FROM support_messages
          WHERE ticket_id = :t AND is_internal = 0 AND body = :b',
        ['t' => $ticketId, 'b' => 'Code reissued and the collection went through.']
    )
);

as_customer();
TestRunner::check(
    'A resolved request can be reopened by replying to it',
    (static function () use ($ref, $ticketId): bool {
        Http::post('/customer/support/reply', ['ref' => $ref, 'body' => 'It has happened again, sorry.']);

        return (string) Database::scalar(
            'SELECT status FROM support_tickets WHERE id = :t',
            ['t' => $ticketId]
        ) === 'open';
    })(),
    Http::flashText()
);

// =============================================================================
TestRunner::section('G. Looking up an order is allowed, and recorded');

as_support();

$subRef = (string) Database::scalar(
    "SELECT sub_number FROM seller_orders WHERE fulfilment_method = 'pickup' LIMIT 1"
);

$lookups = static fn (): int => (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'support.order.viewed'"
);

$startLookups = $lookups();

$noReason = Http::get('/support/orders/' . $subRef);
TestRunner::same('Opening an order with no justification is refused', 303, $noReason->status());
TestRunner::same('And records nothing, because nothing was read', $startLookups, $lookups());

$short = Http::get('/support/orders/' . $subRef, ['ticket' => 'x']);
TestRunner::same('A justification too short to mean anything is refused', 303, $short->status());
TestRunner::same('Still nothing recorded', $startLookups, $lookups());

$opened = Http::get('/support/orders/' . $subRef, ['ticket' => $ref]);
TestRunner::same('With a ticket reference the order opens', 200, $opened->status());

TestRunner::same('And the look is recorded against that ticket', 1, (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_log
      WHERE action = 'support.order.viewed' AND entity_type = 'seller_order'
        AND entity_id = :ref AND justification = :why",
    ['ref' => $subRef, 'why' => $ref]
));

Http::get('/support/orders/SL-DOES-NOT-EXIST-1', ['ticket' => $ref]);
TestRunner::same(
    'A lookup that found nothing is recorded too - "it errored" is not a way to look unseen',
    $startLookups + 2,
    $lookups()
);

TestRunner::check(
    'The search page carries the justification into every result link',
    Http::sees(
        Http::get('/support/orders', ['q' => $subRef, 'ticket' => $ref]),
        'ticket=' . rawurlencode($ref)
    )
);

// =============================================================================
TestRunner::section('H. Support can reissue a code and cannot read one');

// The seeded collection code is pinned by the schema contract suite, so this
// section puts the whole pickup row back afterwards. Reissuing is the point of
// the section; leaving it reissued would fail a different file for a reason
// that has nothing to do with it.
$pickupBefore = Database::selectOne(
    'SELECT p.id, p.code_hash, p.code_issued_at, p.verify_attempts
       FROM order_pickups p
       JOIN seller_orders so ON so.id = p.seller_order_id
      WHERE so.sub_number = :r',
    ['r' => $subRef]
);

$codeHash = static fn (): string => (string) Database::scalar(
    'SELECT p.code_hash FROM order_pickups p
       JOIN seller_orders so ON so.id = p.seller_order_id
      WHERE so.sub_number = :r',
    ['r' => $subRef]
);

$oldHash = $codeHash();

Http::post('/support/orders/reissue-code', ['ref' => $subRef, 'ticket' => '']);
TestRunner::same('Reissuing with no justification changes nothing', $oldHash, $codeHash());

$reissue = Http::post('/support/orders/reissue-code', ['ref' => $subRef, 'ticket' => $ref]);

TestRunner::check('With one, a new code is issued', $codeHash() !== $oldHash);

TestRunner::same('Which is audited as a sensitive action', 1, (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_log
      WHERE action = 'pickup.code.reissued_by_support' AND justification = :why",
    ['why' => $ref]
));

$newCode = (string) Database::scalar(
    "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.code'))
       FROM notifications
      WHERE template_key = 'pickup.code_reissued'
      ORDER BY id DESC LIMIT 1"
);

TestRunner::check('The plaintext code went to the customer', $newCode !== '' && $newCode !== 'null', $newCode);

TestRunner::check(
    'And appears on no page support can open',
    !Http::sees(Http::get('/support/orders/' . $subRef, ['ticket' => $ref]), $newCode)
        && !Http::sees(Http::get('/support/notifications'), $newCode),
    'the reissued code was readable by the agent who reissued it'
);

// =============================================================================
TestRunner::section('I. The support screens render from the database');

foreach ([
    '/support'               => 'overview',
    '/support/tickets'       => 'queue',
    '/support/escalations'   => 'escalations',
    '/support/orders'        => 'order lookup',
    '/support/notifications' => 'message log',
] as $path => $what) {
    TestRunner::same('The ' . $what . ' page renders', 200, Http::get($path)->status());
}

TestRunner::same('A ticket page renders', 200, Http::get('/support/tickets/' . $ref)->status());
TestRunner::same('An unknown ticket reference is a 404', 404, Http::get('/support/tickets/TKT-00-NOPE00')->status());

as_customer();
TestRunner::same(
    'A customer cannot reach the support desk',
    403,
    Http::get('/support/tickets')->status()
);

// =============================================================================
TestRunner::section('J. Preferences: what is optional, and what is not');

$customerId = as_customer();
$consents   = new ConsentRepository();

// Preference rows are updated in place, not appended, so the high-water mark
// does not cover them. Snapshot what is there and put it back at the end -
// leaving marketing switched off would make the retention suite skip every
// reminder for a reason this file caused.
$prefsBefore = Database::select(
    'SELECT id, category, channel, is_enabled FROM notification_preferences WHERE user_id = :u',
    ['u' => $customerId]
);

Http::post('/customer/preferences', ['category' => ['offers', 'reorder']]);

TestRunner::same(
    'Switching optional categories on records them and the consent',
    ['offers' => true, 'reorder' => true, 'consent' => 1],
    [
        'offers'  => $consents->channelEnabled($customerId, NotificationCategory::Offers, NotificationChannel::Email),
        'reorder' => $consents->channelEnabled($customerId, NotificationCategory::Reorder, NotificationChannel::Email),
        'consent' => (int) Database::scalar(
            "SELECT granted FROM consent_records
              WHERE user_id = :u AND consent_type = 'marketing' ORDER BY id DESC LIMIT 1",
            ['u' => $customerId]
        ),
    ]
);

Http::post('/customer/preferences', ['category' => []]);

TestRunner::same(
    'Switching them all off withdraws consent, rather than leaving a record that contradicts the settings',
    ['offers' => false, 'reorder' => false, 'consent' => 0],
    [
        'offers'  => $consents->channelEnabled($customerId, NotificationCategory::Offers, NotificationChannel::Email),
        'reorder' => $consents->channelEnabled($customerId, NotificationCategory::Reorder, NotificationChannel::Email),
        'consent' => (int) Database::scalar(
            "SELECT granted FROM consent_records
              WHERE user_id = :u AND consent_type = 'marketing' ORDER BY id DESC LIMIT 1",
            ['u' => $customerId]
        ),
    ]
);

// The form does not offer this; the only way to send it is to craft it.
Http::post('/customer/preferences', ['category' => ['order_updates', 'pickup_delivery']]);

TestRunner::same(
    'A crafted POST cannot switch off messages about an order they placed',
    ['order_updates' => true, 'pickup_delivery' => true, 'support' => true],
    [
        'order_updates'   => $consents->channelEnabled($customerId, NotificationCategory::OrderUpdates, NotificationChannel::Email),
        'pickup_delivery' => $consents->channelEnabled($customerId, NotificationCategory::PickupDelivery, NotificationChannel::Email),
        'support'         => $consents->channelEnabled($customerId, NotificationCategory::Support, NotificationChannel::Email),
    ]
);

TestRunner::same(
    'Nor does it record consent for something that was not offered',
    0,
    (int) Database::scalar(
        "SELECT COUNT(*) FROM notification_preferences
          WHERE user_id = :u AND category IN ('order_updates','pickup_delivery','support') AND is_enabled = 0",
        ['u' => $customerId]
    )
);

// =============================================================================
TestRunner::section('K. Unsubscribe means now, not next time');

Http::post('/customer/preferences', ['category' => ['offers', 'reorder']]);

Database::statement(
    "INSERT INTO notifications (user_id, channel, category, is_marketing, template_key, payload_json, status)
     VALUES (:u, 'email', 'offers', 1, 'offers.weekly', '{}', 'queued')",
    ['u' => $customerId]
);
$queuedId = (int) Database::scalar('SELECT LAST_INSERT_ID()');

Database::statement(
    "INSERT INTO notifications (user_id, channel, category, is_marketing, template_key, payload_json, status)
     VALUES (:u, 'email', 'order_updates', 0, 'order.confirmed', '{}', 'queued')",
    ['u' => $customerId]
);
$keptId = (int) Database::scalar('SELECT LAST_INSERT_ID()');

Http::post('/customer/unsubscribe');

TestRunner::same(
    'Queued marketing is cancelled, and the order update beside it is untouched',
    ['marketing' => 'cancelled', 'order' => 'queued'],
    [
        'marketing' => (string) Database::scalar('SELECT status FROM notifications WHERE id = :i', ['i' => $queuedId]),
        'order'     => (string) Database::scalar('SELECT status FROM notifications WHERE id = :i', ['i' => $keptId]),
    ]
);

TestRunner::same(
    'The cancelled one says why',
    'consent_withdrawn',
    (string) Database::scalar('SELECT skip_reason FROM notifications WHERE id = :i', ['i' => $queuedId])
);

TestRunner::same(
    'And consent is withdrawn, not merely the channels switched off',
    0,
    (int) Database::scalar(
        "SELECT granted FROM consent_records
          WHERE user_id = :u AND consent_type = 'marketing' ORDER BY id DESC LIMIT 1",
        ['u' => $customerId]
    )
);

TestRunner::check(
    'The consent record is append-only, so the whole history survives',
    (int) Database::scalar(
        "SELECT COUNT(*) FROM consent_records WHERE user_id = :u AND consent_type = 'marketing'",
        ['u' => $customerId]
    ) >= 4
);

Database::statement('DELETE FROM notifications WHERE id IN (:a, :b)', ['a' => $queuedId, 'b' => $keptId]);

// =============================================================================
TestRunner::section('L. The inbox shows what was sent, and no codes');

$unreadBefore = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL',
    ['u' => $customerId]
);

TestRunner::same('The inbox renders', 200, Http::get('/customer/notifications')->status());

Http::post('/customer/notifications/read');

TestRunner::same(
    'Marking all read clears them',
    0,
    (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications
          WHERE user_id = :u AND read_at IS NULL AND status IN ('delivered','queued','sending')",
        ['u' => $customerId]
    )
);

TestRunner::check(
    'And says so rather than returning the same page in silence',
    Http::flashText() !== '',
    'no flash'
);

$otherUnread = (int) Database::scalar(
    'SELECT COUNT(*) FROM notifications WHERE user_id <> :u AND read_at IS NULL',
    ['u' => $customerId]
);
TestRunner::check(
    "Marking mine read leaves everybody else's alone",
    $otherUnread > 0 || $unreadBefore === 0,
    'other customers were marked read too'
);

// =============================================================================
TestRunner::section('M. Reorder re-checks, and says what changed');

// This section puts things IN the basket, and CartService increases the
// quantity of a line that is already there rather than adding a second one.
// Left alone, a second run of this file would be adding to a basket the first
// run filled, and would eventually be refused for exceeding stock - a failure
// with nothing to do with what is being tested.
$basketBefore = Database::select(
    'SELECT ci.id, ci.qty FROM cart_items ci
       JOIN carts c ON c.id = ci.cart_id
      WHERE c.user_id = :u',
    ['u' => $customerId]
);

$service = new ReorderService();
$items   = $service->revalidate($customerId, 24);

TestRunner::check('There is something to reorder', $items !== [], (string) count($items));

TestRunner::same(
    'Every line carries an outcome the page knows how to render',
    [],
    array_values(array_diff(
        array_unique(array_column($items, 'outcome')),
        ['available', 'price_changed', 'partial', 'unavailable']
    ))
);

$first = $items[0];

// "Partial" only means anything for a line they bought more than one of, so
// the fixture below uses one of those rather than asserting a tautology
// against whatever happens to be first.
$multi = current(array_filter($items, static fn (array $i): bool => $i['last_qty'] > 1));

TestRunner::check(
    'At least one line was bought in quantity, so "partial" can be exercised',
    $multi !== false,
    'every candidate has last_qty = 1'
);

// Price change: move the seller's price and the page must say so rather than
// quietly charging the new one.
$originalPrice = (string) Database::scalar(
    'SELECT price FROM products WHERE id = :p',
    ['p' => $first['product_id']]
);
Database::statement(
    'UPDATE products SET price = price + 500 WHERE id = :p',
    ['p' => $first['product_id']]
);

$afterPrice = current(array_filter(
    $service->revalidate($customerId, 24),
    static fn (array $i): bool => $i['product_id'] === $first['product_id']
));

TestRunner::same('A price that moved is flagged, not absorbed', 'price_changed', $afterPrice['outcome']);
TestRunner::check(
    'And both prices are shown, so the difference is theirs to see',
    $afterPrice['last_price'] !== $afterPrice['current_price'],
    $afterPrice['last_price'] . ' -> ' . $afterPrice['current_price']
);

Database::statement(
    'UPDATE products SET price = :price WHERE id = :p',
    ['price' => $originalPrice, 'p' => $first['product_id']]
);

// Partial: leave the multi-quantity line with less stock than they bought.
//
// `qty_available` is a GENERATED column (on hand minus reserved), so the
// fixture moves qty_on_hand and puts it back afterwards. Writing the derived
// column is rejected by the database, which is the schema working as intended.
$stock = Database::select(
    'SELECT id, qty_on_hand, qty_reserved FROM inventory WHERE product_id = :p',
    ['p' => $multi['product_id']]
);

$setAvailable = static function (int $rowId, int $available) use ($stock): void {
    $row = current(array_filter($stock, static fn (array $r): bool => (int) $r['id'] === $rowId));

    Database::statement(
        'UPDATE inventory SET qty_on_hand = :hand WHERE id = :i',
        ['hand' => (int) $row['qty_reserved'] + $available, 'i' => $rowId]
    );
};

// Whatever the seeded basket already holds counts against the same stock, so
// the fixture leaves room for it. Otherwise this would be testing "the basket
// is already full", which is a different rule and has its own check below.
$alreadyHeld = (int) Database::scalar(
    'SELECT COALESCE(SUM(ci.qty), 0) FROM cart_items ci
       JOIN carts c ON c.id = ci.cart_id
      WHERE c.user_id = :u AND ci.product_id = :p',
    ['u' => $customerId, 'p' => $multi['product_id']]
);

foreach ($stock as $row) {
    $setAvailable((int) $row['id'], 0);
}
$setAvailable((int) $stock[0]['id'], $alreadyHeld + $multi['last_qty'] - 1);

$partial = current(array_filter(
    $service->revalidate($customerId, 24),
    static fn (array $i): bool => $i['product_id'] === $multi['product_id']
));

TestRunner::same(
    'Less stock than they bought last time is "partial", not a silent reduction',
    'partial',
    $partial['outcome']
);

Http::post('/customer/reorder/add', ['product_id' => $multi['product_id']]);
TestRunner::check(
    'Adding a partial line adds what there is and says so, rather than quietly reducing it',
    str_contains(Http::flashText(), 'that is all'),
    Http::flashText()
);

// Unavailable: no stock anywhere.
foreach ($stock as $row) {
    $setAvailable((int) $row['id'], 0);
}

$gone = current(array_filter(
    $service->revalidate($customerId, 24),
    static fn (array $i): bool => $i['product_id'] === $multi['product_id']
));

TestRunner::same(
    'Nothing left anywhere is "unavailable", and the row stays so it can say so',
    'unavailable',
    $gone['outcome']
);

Http::post('/customer/reorder/add', ['product_id' => $multi['product_id']]);
TestRunner::check(
    'Adding an unavailable line is refused, and nothing is substituted for it',
    str_contains(Http::flashText(), 'no longer offering'),
    Http::flashText()
);

foreach ($stock as $row) {
    Database::statement(
        'UPDATE inventory SET qty_on_hand = :hand WHERE id = :i',
        ['hand' => (int) $row['qty_on_hand'], 'i' => (int) $row['id']]
    );
}

// A product they have never bought is not reorderable, whatever id is posted.
$never = (int) Database::scalar(
    'SELECT p.id FROM products p
      WHERE p.id NOT IN (
            SELECT i.product_id FROM order_items i
              JOIN seller_orders so ON so.id = i.seller_order_id
              JOIN orders o         ON o.id = so.order_id
             WHERE o.user_id = :u)
      LIMIT 1',
    ['u' => $customerId]
);

Http::post('/customer/reorder/add', ['product_id' => $never]);
TestRunner::check(
    'A product they never bought cannot be added through the reorder route',
    str_contains(Http::flashText(), 'not something you have bought'),
    Http::flashText()
);

$added = Http::post('/customer/reorder/add', ['product_id' => $first['product_id']]);
TestRunner::same('An available line can be added', 303, $added->status());
TestRunner::check(
    'And the basket says what went in at what price',
    str_contains(Http::flashText(), "today's price") || str_contains(Http::flashText(), 'added'),
    Http::flashText()
);

// =============================================================================
TestRunner::section('N. The public contact form opens a real ticket');

Http::newVisitor();
$publicPage = Http::get('/contact');

TestRunner::same('The page renders for a signed-out visitor', 200, $publicPage->status());
TestRunner::check(
    'Who is shown the way in rather than a form that could not work',
    Http::sees($publicPage, 'Create an account') && !Http::sees($publicPage, 'name="subject"'),
    'a form with nowhere to send the reply was shown'
);

as_customer();
TestRunner::check(
    'A signed-in customer gets the real form, posting to the real handler',
    Http::sees(Http::get('/contact'), 'name="subject"')
);

// =============================================================================
// Teardown. Everything this file created, and nothing it did not.
// =============================================================================

$madeTickets = array_values(array_filter($madeTickets));

foreach ($madeTickets as $made) {
    $id = ticket_id($made);

    if ($id === 0) {
        continue;
    }

    Database::statement('DELETE FROM notifications WHERE reference_type = :t AND reference_id = :i', [
        't' => 'support_ticket', 'i' => $id,
    ]);
    Database::statement('DELETE FROM support_messages WHERE ticket_id = :i', ['i' => $id]);
    Database::statement('DELETE FROM audit_log WHERE entity_type = :t AND entity_id = :i', [
        't' => 'support_ticket', 'i' => (string) $id,
    ]);
    Database::statement('DELETE FROM support_tickets WHERE id = :i', ['i' => $id]);
}

// The seeded ticket this file drove through resolve, escalate and reopen is
// left where it started, so a second run sees the same queue as the first.
foreach ([$ticketId] as $seeded) {
    Database::statement(
        "UPDATE support_tickets
            SET status = 'open', assigned_to = NULL, escalated_to = NULL,
                escalation_reason = NULL, priority = 'normal', resolved_at = NULL
          WHERE id = :i",
        ['i' => $seeded]
    );
}

Database::statement("DELETE FROM notifications WHERE template_key = 'pickup.code_reissued'");

// The collection code, back to the one the seed issued.
if ($pickupBefore !== null) {
    Database::statement(
        'UPDATE order_pickups
            SET code_hash = :hash, code_issued_at = :issued, verify_attempts = :tries
          WHERE id = :id',
        [
            'hash'   => $pickupBefore['code_hash'],
            'issued' => $pickupBefore['code_issued_at'],
            'tries'  => (int) $pickupBefore['verify_attempts'],
            'id'     => (int) $pickupBefore['id'],
        ]
    );
}

// Notification preferences, back to what the seed set.
$keepPrefs = array_map(static fn (array $r): int => (int) $r['id'], $prefsBefore);

Database::statement(
    'DELETE FROM notification_preferences WHERE user_id = :u'
    . ($keepPrefs === [] ? '' : ' AND id NOT IN (' . implode(',', $keepPrefs) . ')'),
    ['u' => $customerId]
);

foreach ($prefsBefore as $row) {
    Database::statement(
        'UPDATE notification_preferences SET is_enabled = :on WHERE id = :i',
        ['on' => (int) $row['is_enabled'], 'i' => (int) $row['id']]
    );
}

// The basket, back to what it held before section M.
$keep = array_map(static fn (array $r): int => (int) $r['id'], $basketBefore);

Database::statement(
    'DELETE ci FROM cart_items ci JOIN carts c ON c.id = ci.cart_id
      WHERE c.user_id = :u' . ($keep === [] ? '' : ' AND ci.id NOT IN (' . implode(',', $keep) . ')'),
    ['u' => $customerId]
);

foreach ($basketBefore as $row) {
    Database::statement(
        'UPDATE cart_items SET qty = :q WHERE id = :i',
        ['q' => (int) $row['qty'], 'i' => (int) $row['id']]
    );
}

// Everything this run appended to the two append-only tables, and nothing
// that was there before it started.
Database::statement('DELETE FROM audit_log WHERE id > :mark', ['mark' => $highWater['audit_log']]);
Database::statement('DELETE FROM consent_records WHERE id > :mark', ['mark' => $highWater['consent_records']]);

// The deliberate wrong-password logins above count against this IP, and a
// throttled next run would fail for a reason that has nothing to do with it.
Database::statement('DELETE FROM auth_attempts WHERE ip_address = INET6_ATON(:ip)', ['ip' => TEST_IP]);

TestRunner::finish();
