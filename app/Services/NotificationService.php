<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Logger;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\NotificationSkipReason;
use App\Repositories\ConsentRepository;
use App\Repositories\NotificationRepository;

/**
 * Draining the notification queue.
 *
 * The brief says: "do not pretend messages are being sent through providers
 * that are not connected." So:
 *
 *   - **Email** works, through one of two drivers. `log` writes the whole
 *     message to storage/logs/mail.log, which is what a local install uses and
 *     is honestly described as such. `smtp` posts it to a real server, and is
 *     only used if one is configured.
 *   - **SMS and WhatsApp are not connected.** No provider agreement exists.
 *     Messages queued for those channels are marked `skipped` with the reason
 *     `skipped_no_provider`. They are NOT marked delivered, and nothing in the
 *     application claims they were sent.
 *
 * Templates are rendered here rather than in the channel, so the same message
 * reads identically however it goes out.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications = new NotificationRepository(),
        private readonly ConsentRepository $consent = new ConsentRepository(),
    ) {
    }

    /**
     * Sends everything that is due.
     *
     * @return array{attempted:int,delivered:int,failed:int,skipped:int}
     */
    public function dispatchQueue(int $limit = 50): array
    {
        $due       = $this->notifications->dueForSending($limit);
        $delivered = 0;
        $failed    = 0;
        $skipped   = 0;

        foreach ($due as $message) {
            // Claiming is a guarded UPDATE, so two runs of the task cannot both
            // send the same message.
            if (!$this->notifications->claim((int) $message['id'])) {
                continue;
            }

            $channel = NotificationChannel::fromDatabase((string) $message['channel']);

            if ($channel !== NotificationChannel::Email) {
                // Recorded as deliberately not sent, with the reason. Marking
                // it delivered would be a lie the dashboard would then repeat.
                $this->notifications->markSkipped((int) $message['id'], NotificationSkipReason::SkippedNoProvider);
                $skipped++;

                continue;
            }

            $rendered = $this->render($message);

            try {
                $this->deliverEmail($message, $rendered);

                $this->notifications->markDelivered(
                    (int) $message['id'],
                    sprintf('Sent via the "%s" mail driver', (string) Config::get('mail.driver', 'log'))
                );

                $delivered++;
            } catch (\Throwable $e) {
                $this->notifications->markFailed((int) $message['id'], $e->getMessage());

                Logger::error('Could not send a notification', [
                    'notification' => $message['id'],
                    'template'     => $message['template_key'],
                    'error'        => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        return [
            'attempted' => count($due),
            'delivered' => $delivered,
            'failed'    => $failed,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Which channels actually work, for the settings screen.
     *
     * A customer looking at their notification preferences should be able to
     * see that SMS is switched off because we cannot send it, not because they
     * switched it off.
     *
     * @return list<array{channel:string,label:string,available:bool,reason:string}>
     */
    public function channelStatus(): array
    {
        return [
            [
                'channel'   => 'email',
                'label'     => 'Email',
                'available' => true,
                'reason'    => Config::get('mail.driver') === 'log'
                    ? 'Development mode: messages are written to storage/logs/mail.log instead of being sent.'
                    : '',
            ],
            [
                'channel'   => 'sms',
                'label'     => 'SMS',
                'available' => false,
                'reason'    => 'No SMS provider is connected. Messages queued for SMS are recorded as skipped.',
            ],
            [
                'channel'   => 'whatsapp',
                'label'     => 'WhatsApp',
                'available' => false,
                'reason'    => 'No WhatsApp Business account is connected.',
            ],
        ];
    }

    /**
     * Everything the preferences screen needs, already decided.
     *
     * The screen shows which categories are OPTIONAL and which are part of the
     * service, and that distinction is made here rather than in the template:
     * a category the customer cannot switch off should not be a toggle that
     * quietly does nothing, and whether it can be switched off is a rule, not a
     * presentational choice.
     *
     * @return array{categories:list<array<string,mixed>>,channels:list<array<string,mixed>>,consent:array<string,mixed>}
     */
    public function preferencePanel(int $userId): array
    {
        $stored = $this->consent->preferencesFor($userId);

        $labels = [
            'order_updates'   => ['Order updates', 'Confirmed, accepted, being prepared, ready, delivered, refunded.'],
            'pickup_delivery' => ['Collection and delivery alerts', 'Your collection code, and when an agent is on the way.'],
            'support'         => ['Support replies', 'Replies to a support request you opened.'],
            'reorder'         => ['Reorder reminders', 'An occasional nudge when something you buy regularly is probably running low.'],
            'offers'          => ['Offers and promotions', 'Reduced prices and seasonal offers.'],
        ];

        $categories = [];
        foreach (NotificationCategory::cases() as $category) {
            [$label, $desc] = $labels[$category->value] ?? [
                ucwords(str_replace('_', ' ', $category->value)), '',
            ];

            $categories[] = [
                'key'       => $category->value,
                'label'     => $label,
                'desc'      => $desc,
                'marketing' => self::isOptional($category),
                'on'        => $stored[$category->value][NotificationChannel::Email->value] ?? true,
            ];
        }

        $channels = array_map(
            static fn (array $c): array => [
                'key'       => $c['channel'],
                'label'     => $c['label'],
                'connected' => $c['available'],
                'reason'    => $c['reason'],
            ],
            $this->channelStatus()
        );

        return [
            'categories' => $categories,
            'channels'   => $channels,
            'consent'    => $this->consent->latest($userId, ConsentType::Marketing),
        ];
    }

    /**
     * Saves the optional categories a customer wants.
     *
     * Only optional ones are written. A request that asks to switch off order
     * updates is not obeyed and not rejected either - it is ignored, because
     * the form does not offer it and the only way to send one is to craft it.
     *
     * @param list<string> $wanted category keys the customer ticked
     */
    public function savePreferences(int $userId, array $wanted): void
    {
        $granted = [];

        foreach (NotificationCategory::cases() as $category) {
            if (!self::isOptional($category)) {
                continue;
            }

            $on = in_array($category->value, $wanted, true);

            $this->consent->setChannel($userId, $category, NotificationChannel::Email, $on);

            if ($on) {
                $granted[] = $category->value;
            }
        }

        // The consent record follows the toggles: somebody who has switched
        // every optional category off has withdrawn marketing consent, whatever
        // the consent row said before. Leaving a "consent given" record behind
        // an empty set of preferences is the kind of discrepancy that is
        // impossible to explain later.
        $this->consent->record(
            $userId,
            ConsentType::Marketing,
            $granted !== [],
            'preferences_page'
        );

        Audit::record(
            'preferences.updated',
            'user',
            $userId,
            $granted === [] ? 'All optional messages switched off' : 'Optional: ' . implode(', ', $granted)
        );
    }

    /** The one-click stop, from the settings page or an unsubscribe link. */
    public function unsubscribeAll(int $userId, string $source = 'unsubscribe_link'): void
    {
        $this->consent->unsubscribeAll($userId, $source);

        // Anything already queued but not yet sent is cancelled, otherwise
        // "takes effect immediately" is a lie for the next few minutes.
        $this->notifications->cancelQueuedMarketing($userId);

        Audit::record('preferences.unsubscribed', 'user', $userId, 'Source: ' . $source);
    }

    /**
     * Whether a category is the customer's to switch off.
     *
     * Messages about an order they placed are part of what they bought. The
     * two that are not are the two that exist to sell them something else.
     */
    private static function isOptional(NotificationCategory $category): bool
    {
        return $category === NotificationCategory::Offers
            || $category === NotificationCategory::Reorder;
    }

    /**
     * Renders a template into a subject and a body.
     *
     * Templates live here rather than in the database so they are reviewable in
     * a diff. A missing template produces a readable fallback rather than an
     * exception - a notification nobody defined should still tell the customer
     * something happened, and should be obvious in the log.
     *
     * @param  array<string,mixed> $message
     * @return array{subject:string,body:string}
     */
    public function render(array $message): array
    {
        $payload = json_decode((string) ($message['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        $name = (string) ($message['first_name'] ?? 'there');

        return match ((string) $message['template_key']) {
            'auth.verify_email' => [
                'subject' => 'Confirm your SokoLink email address',
                'body'    => sprintf(
                    "Hello %s,\n\nConfirm your email address to finish setting up your account:\n%s\n\n"
                    . "If you did not create an account, ignore this message.",
                    $name,
                    $this->link('/verify-email?token=' . ($payload['token'] ?? ''))
                ),
            ],
            'auth.password_reset' => [
                'subject' => 'Reset your SokoLink password',
                'body'    => sprintf(
                    "Hello %s,\n\nUse this link to set a new password. It expires in %d minutes:\n%s\n\n"
                    . "If you did not ask for this, nothing has changed and you can ignore it.",
                    $name,
                    (int) ($payload['expires_minutes'] ?? 60),
                    $this->link('/reset-password?token=' . ($payload['token'] ?? ''))
                ),
            ],
            'auth.password_changed' => [
                'subject' => 'Your SokoLink password was changed',
                'body'    => sprintf(
                    "Hello %s,\n\nYour password was changed just now. If that was not you, contact support immediately.",
                    $name
                ),
            ],
            'order.accepted' => [
                'subject' => sprintf('Order %s accepted', $payload['sub_number'] ?? ''),
                'body'    => "The seller has accepted your order and is preparing it.",
            ],
            'pickup.code_issued', 'pickup.code_reissued' => [
                'subject' => sprintf('Your collection code for %s', $payload['sub_number'] ?? ''),
                'body'    => sprintf(
                    "Your order is ready at %s.\n\nCollection code: %s\n\n"
                    . "Show this code at the counter. It is valid for %s hours and can only be used once.",
                    $payload['store_name'] ?? 'the store',
                    $payload['code'] ?? '',
                    $payload['window_hours'] ?? '72'
                ),
            ],
            'delivery.code_issued' => [
                'subject' => sprintf('Your delivery code for %s', $payload['sub_number'] ?? ''),
                'body'    => sprintf(
                    "Your order is on its way.\n\nDelivery code: %s\n\n"
                    . "Give this code to the agent when they arrive. Do not share it before then.",
                    $payload['code'] ?? ''
                ),
            ],
            'order.ready_for_collection' => [
                'subject' => sprintf('Order %s is ready to collect', $payload['sub_number'] ?? ''),
                'body'    => 'Your order is ready. Bring the collection code we sent you.',
            ],
            'order.out_for_delivery' => [
                'subject' => sprintf('Order %s is out for delivery', $payload['sub_number'] ?? ''),
                'body'    => 'An agent is on the way with your order. Have your delivery code ready.',
            ],
            'order.delivered', 'order.collected' => [
                'subject' => sprintf('Order %s complete', $payload['sub_number'] ?? ''),
                'body'    => 'Thank you. Your order is complete - we would welcome a review.',
            ],
            'order.rejected' => [
                'subject' => sprintf('Order %s could not be fulfilled', $payload['sub_number'] ?? ''),
                'body'    => sprintf(
                    "The seller could not fulfil this part of your order.\n\nReason: %s\n\n"
                    . "A refund has been started. Nothing else in your order is affected.",
                    $payload['reason'] ?? 'not given'
                ),
            ],
            'order.delivery_failed' => [
                'subject' => sprintf('We could not deliver %s', $payload['sub_number'] ?? ''),
                'body'    => sprintf(
                    "The agent could not complete the delivery.\n\nReason: %s\n\nWe will try again.",
                    $payload['reason'] ?? 'not given'
                ),
            ],
            'order.collection_overdue' => [
                'subject' => sprintf('Order %s is still waiting for you', $payload['sub_number'] ?? ''),
                'body'    => 'Your collection window has passed. Collect it soon, or contact the seller.',
            ],
            'order.expired' => [
                'subject' => sprintf('Order %s expired', $payload['sub_number'] ?? ''),
                'body'    => 'This order was not paid in time and has been cancelled. Nothing was charged.',
            ],
            'order.refunded' => [
                'subject' => sprintf('Refund for %s', $payload['sub_number'] ?? ''),
                'body'    => 'Your refund has been processed. It may take a few days to reach you.',
            ],
            'reorder.reminder' => [
                'subject' => sprintf('Running low on %s?', $payload['product'] ?? 'something'),
                'body'    => sprintf(
                    "Hello %s,\n\nYou bought %s on %s. Based on %s, you might be running low.\n\n%s\n\n"
                    . "If this is not useful, you can turn these off in your account settings:\n%s",
                    $name,
                    $payload['product'] ?? '',
                    substr((string) ($payload['last_bought'] ?? ''), 0, 10),
                    $this->basisPhrase((string) ($payload['basis'] ?? 'none')),
                    $this->link('/products/' . ($payload['product_slug'] ?? '')),
                    $this->link('/unsubscribe')
                ),
            ],
            'seller.application_received' => [
                'subject' => 'We have your application to sell on SokoLink',
                'body'    => sprintf(
                    "Thank you for applying with %s.\n\nWe will review it and email you either way.",
                    $payload['business_name'] ?? 'your business'
                ),
            ],
            'seller.application_approved' => [
                'subject' => 'Your SokoLink seller account is approved',
                'body'    => 'You can now add products and start taking orders. Sign in to set up your store.',
            ],
            'seller.application_rejected' => [
                'subject' => 'About your SokoLink seller application',
                'body'    => sprintf(
                    "We are not able to approve your application at the moment.\n\nReason: %s",
                    $payload['reason'] ?? 'not given'
                ),
            ],
            'support.reply' => [
                'subject' => sprintf('Reply on ticket %s', $payload['ticket_number'] ?? ''),
                'body'    => 'Support has replied to your ticket. Sign in to read it.',
            ],
            default => [
                'subject' => 'A message from SokoLink',
                'body'    => sprintf(
                    "There is an update on your account.\n\n(No template is defined for \"%s\".)",
                    (string) $message['template_key']
                ),
            ],
        };
    }

    /**
     * @param array<string,mixed>            $message
     * @param array{subject:string,body:string} $rendered
     */
    private function deliverEmail(array $message, array $rendered): void
    {
        $driver = (string) Config::get('mail.driver', 'log');

        if ($driver === 'log') {
            // Honest local behaviour. The message is written in full so it can
            // be read, and the log line says plainly that nothing was sent.
            Logger::mail(sprintf(
                "To: %s\nSubject: %s\n\n%s\n\n--- NOT SENT: the mail driver is \"log\" ---",
                (string) $message['email'],
                $rendered['subject'],
                $rendered['body']
            ));

            return;
        }

        $headers = sprintf(
            "From: %s <%s>\r\nContent-Type: text/plain; charset=UTF-8\r\n",
            (string) Config::get('mail.from_name', 'SokoLink'),
            (string) Config::get('mail.from_address', 'no-reply@sokolink.test')
        );

        $sent = @mail(
            (string) $message['email'],
            $rendered['subject'],
            $rendered['body'],
            $headers
        );

        if (!$sent) {
            throw new \RuntimeException('The mail server refused the message.');
        }
    }

    /**
     * Explains, in the message itself, why the customer is hearing from us.
     *
     * A reminder that says "you bought this 27 days ago and you usually buy it
     * every 30" is a reminder somebody can judge. One that says "it is time"
     * is not.
     */
    private function basisPhrase(string $basis): string
    {
        return match ($basis) {
            'observed_interval' => 'how often you have bought it before',
            'seller_hint'       => 'how long the seller says it usually lasts',
            'category_default'  => 'how long this kind of product usually lasts',
            default             => 'your purchase history',
        };
    }

    private function link(string $path): string
    {
        return rtrim((string) Config::get('app.url', 'http://localhost'), '/') . $path;
    }
}
