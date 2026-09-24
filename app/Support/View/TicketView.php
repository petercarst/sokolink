<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * A support ticket, shaped for the two audiences that read one.
 *
 * **The difference between them is the point of this class.** A support agent
 * sees the customer's email, the internal notes, who the ticket is assigned to
 * and how long it has been waiting. A customer sees their own thread with the
 * internal notes gone.
 *
 * The notes are removed by the QUERY behind the customer view
 * (`SupportRepository::customerMessages()` filters `is_internal = 0` in SQL),
 * not by this class and not by a template. That is deliberate and it is the
 * rule the whole area exists to enforce: a refactor of a view cannot leak an
 * internal note, because the row never arrives. What this class does is shape
 * what it is given — so `customer()` is safe even if somebody hands it staff
 * rows, but nothing should, and nothing does.
 */
final class TicketView
{
    /**
     * A row for the support queue.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function row(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'ref'           => (string) $row['ticket_ref'],
            'subject'       => (string) $row['subject'],
            'category'      => (string) $row['category'],
            'priority'      => (string) $row['priority'],
            'status'        => (string) $row['status'],
            'customer_name' => trim((string) ($row['customer_name'] ?? '')) ?: 'A customer',
            'order_ref'     => ($row['order_number'] ?? null) !== null ? (string) $row['order_number'] : null,
            'assignee'      => trim((string) ($row['assignee_name'] ?? '')) ?: null,
            'messages'      => (int) ($row['message_count'] ?? 0),
            'preview'       => (string) ($row['first_message'] ?? ''),
            'age_hours'     => (int) ($row['age_hours'] ?? 0),
            'escalated'     => ($row['escalated_to'] ?? null) !== null,
            'escalation_reason' => ($row['escalation_reason'] ?? null) !== null
                ? (string) $row['escalation_reason']
                : null,
            'created_at_utc' => (string) $row['created_at'],
            'updated_at_utc' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function rows(array $rows): array
    {
        return array_map(self::row(...), $rows);
    }

    /**
     * The staff view of one ticket: everything, including the notes.
     *
     * @param  array<string,mixed>       $ticket
     * @param  list<array<string,mixed>> $messages from staffMessages()
     * @return array<string,mixed>
     */
    public static function staff(array $ticket, array $messages): array
    {
        return array_merge(self::row($ticket), [
            'customer_email' => (string) ($ticket['customer_email'] ?? ''),
            'customer_id'    => (int) ($ticket['user_id'] ?? 0),
            'messages'       => self::messages($messages),
        ]);
    }

    /**
     * The customer's view of their own ticket.
     *
     * No assignee - which member of staff is handling it is not the customer's
     * business and changes as shifts do. No internal notes, because the query
     * did not return them.
     *
     * @param  array<string,mixed>       $ticket
     * @param  list<array<string,mixed>> $messages from customerMessages()
     * @return array<string,mixed>
     */
    public static function customer(array $ticket, array $messages): array
    {
        $shaped = self::row($ticket);

        unset($shaped['assignee'], $shaped['escalated']);

        return array_merge($shaped, [
            'messages' => self::messages(array_values(array_filter(
                $messages,
                // Belt as well as braces. The query already excludes these; if
                // one ever arrives here it is a bug, and a bug that shows a
                // customer an internal note is the worst kind this area has.
                static fn (array $m): bool => empty($m['is_internal'])
            ))),
        ]);
    }

    /**
     * @param  list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private static function messages(array $messages): array
    {
        return array_map(
            static fn (array $m): array => [
                'id'     => (int) $m['id'],
                // The customer query labels the author ("You" / "SokoLink
                // support") rather than naming a member of staff; the staff
                // query names them. Whichever arrives is what is shown.
                'author' => trim((string) ($m['author_name'] ?? $m['author_label'] ?? '')) ?: 'System',
                'role'   => (string) $m['author_role'],
                'body'   => (string) $m['body'],
                'internal' => (bool) ($m['is_internal'] ?? false),
                'at_utc' => (string) $m['created_at'],
            ],
            $messages
        );
    }
}
