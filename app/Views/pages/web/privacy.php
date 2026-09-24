<?php
declare(strict_types=1);
/**
 * Privacy notice - TRANSACTIONAL track (see terms.php for why).
 *
 * This page describes the data model the system actually implements: who can
 * see what is specified in USER_ROLES_AND_PERMISSIONS.md section 4, and the
 * consent rules in FR-CRM-05 to FR-CRM-07.
 */
?>

<section class="sl-section-tight">
    <div class="sl-container-read sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Privacy notice', 'url' => null],
            ],
        ]) ?>

        <h1 class="t-display-lg tw-mb-4">Privacy notice</h1>
        <p class="t-caption t-muted tw-mb-8">Version 0.1 (draft) &middot; last updated 21 September 2026</p>

        <div class="sl-alert sl-alert-warn tw-mb-10">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
            <span>
                <strong>Draft, not legally reviewed.</strong>
                What follows accurately describes how the system is designed to handle data, but it
                has not been checked against the Tanzania Personal Data Protection Act or any other
                applicable law. It must be reviewed before launch.
            </span>
        </div>

        <article class="tw-flex tw-flex-col tw-gap-10">

            <section>
                <h2 class="t-heading-xl tw-mb-4">What we collect</h2>
                <div class="tw-overflow-x-auto">
                    <div class="sl-table-scroll">
                    <table class="sl-table">
                        <caption class="visually-hidden">Categories of personal data collected and why</caption>
                        <thead>
                            <tr><th scope="col">Data</th><th scope="col">Why we hold it</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Name, email, phone</td>
                                <td>To identify your account and let sellers and agents reach you about an order.</td>
                            </tr>
                            <tr>
                                <td>Delivery addresses</td>
                                <td>To work out the delivery zone and charge, and to get the order to you.</td>
                            </tr>
                            <tr>
                                <td>Orders and their history</td>
                                <td>To fulfil the order, show you your history, and keep accurate financial records.</td>
                            </tr>
                            <tr>
                                <td>Payment records</td>
                                <td>To confirm payment and handle refunds. We never store card numbers, PINs or mobile-money credentials.</td>
                            </tr>
                            <tr>
                                <td>Notification preferences and consent</td>
                                <td>To know what you have agreed to receive, and when you agreed to it.</td>
                            </tr>
                            <tr>
                                <td>Reviews and support tickets</td>
                                <td>To publish verified reviews and to resolve problems you raise.</td>
                            </tr>
                            <tr>
                                <td>Security and audit logs</td>
                                <td>To detect misuse and to record who accessed what.</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="t-heading-xl tw-mb-4">Who can see your data</h2>
                <p class="t-body-md tw-mb-4">
                    Access is restricted by role, and then restricted again to the specific records a
                    person is entitled to. It is enforced on the server, not by hiding buttons.
                </p>
                <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-3">
                    <li class="t-body-md">
                        <strong>Sellers</strong> see only the part of an order they are fulfilling:
                        your first name, the items, and for a delivery the address needed to pack it.
                        Not your email address, not your other orders, not your payment details.
                    </li>
                    <li class="t-body-md">
                        <strong>Delivery agents</strong> see only tasks assigned to them, and only the
                        recipient name, address, instructions and a masked phone number.
                    </li>
                    <li class="t-body-md">
                        <strong>Support staff</strong> can open the record of a customer who has a
                        ticket with them. They cannot see password hashes, reset tokens, collection
                        codes in plain text, or payment credentials. Every time they open a customer
                        record it is written to the audit log.
                    </li>
                    <li class="t-body-md">
                        <strong>Administrators</strong> can see operational data across the platform.
                        Their actions on another person's data are audited.
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="t-heading-xl tw-mb-4">Messages we send you</h2>
                <p class="t-body-md tw-mb-3">
                    <strong>Service messages</strong> - order confirmed, ready to collect, out for
                    delivery, delivered, refunded - are part of the service you bought and are always
                    sent.
                </p>
                <p class="t-body-md tw-mb-3">
                    <strong>Marketing messages</strong> - reorder reminders and offers - are only sent
                    if you have turned them on. That box is never ticked by default. Every marketing
                    message carries a one-click unsubscribe link that works without logging in, and
                    withdrawing consent cancels messages that are already queued but not yet sent.
                </p>
                <p class="t-body-md tw-mb-0">
                    Reminders are also capped per month, respect quiet hours, and are suppressed
                    entirely if you have already repurchased the item.
                </p>
            </section>

            <section>
                <h2 class="t-heading-xl tw-mb-4">How long we keep it</h2>
                <p class="t-body-md tw-mb-0">
                    If you close your account we minimise your personal data. Order and payment
                    records are kept because they are financial records - a receipt you were given and
                    a seller's sales history are facts, not profile fields, and deleting them would
                    make both parties' records wrong.
                </p>
            </section>

            <section>
                <h2 class="t-heading-xl tw-mb-4">Your choices</h2>
                <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
                    <li class="t-body-md">See and correct your profile and addresses in your account.</li>
                    <li class="t-body-md">Change what you receive, per channel and per category, at any time.</li>
                    <li class="t-body-md">
                        <a href="<?= e(route('page.unsubscribe')) ?>">Unsubscribe from marketing</a>
                        without logging in.
                    </li>
                    <li class="t-body-md">Ask support for a copy of your data, or to close your account.</li>
                </ul>
            </section>

            <section>
                <h2 class="t-heading-xl tw-mb-4">Security</h2>
                <p class="t-body-md tw-mb-0">
                    Passwords are stored as one-way hashes and never in a readable form. Collection and
                    delivery codes are stored hashed too - nobody at SokoLink can read them back, they
                    can only be regenerated, and regenerating one is audited and tells you it happened.
                </p>
            </section>
        </article>

        <hr class="sl-divider tw-my-10">

        <p class="t-caption t-muted tw-mb-0">
            Questions about your data? <a href="<?= e(route('page.contact')) ?>">Contact support</a>.
            See also our <a href="<?= e(route('page.terms')) ?>">terms of service</a>.
        </p>
    </div>
</section>
