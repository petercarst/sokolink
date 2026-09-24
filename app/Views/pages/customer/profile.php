<?php
declare(strict_types=1);
/**
 * Customer profile.
 *
 * Email change and password change are deliberately separate forms with their
 * own confirmation requirements - bundling them into one "save" is how an
 * account takeover gets easier.
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Profile',
        'subtitle' => 'Your details, how sellers and delivery agents reach you, and your password.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Your details</h2>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="profile_update">

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'value' => 'Asha', 'required' => true, 'autocomplete' => 'given-name']) ?>
                        <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'value' => 'Mwinyi', 'required' => true, 'autocomplete' => 'family-name']) ?>
                    </div>

                    <?= component('field', [
                        'name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel',
                        'value' => '+255 712 345 118', 'required' => true, 'autocomplete' => 'tel',
                        'help' => 'Sellers and delivery agents see a masked version of this, never the full number, until they have an order with you.',
                    ]) ?>

                    <?= component('field', [
                        'name' => 'language', 'label' => 'Language', 'type' => 'select',
                        'value' => 'en', 'options' => ['en' => 'English', 'sw' => 'Kiswahili (coming soon)'],
                        'help' => 'Kiswahili is not translated yet. The interface is built so it can be.',
                    ]) ?>

                    <button class="sl-btn sl-btn-primary" type="submit">Save changes</button>
                </form>
            </section>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">Email address</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Changing this sends a confirmation link to the new address. The old address keeps
                    working until you confirm, and is told that a change was requested.
                </p>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="email_change">
                    <?= component('field', [
                        'name' => 'email', 'label' => 'Email address', 'type' => 'email',
                        'value' => 'customer.asha@sokolink.test', 'required' => true, 'autocomplete' => 'email',
                    ]) ?>
                    <?= component('field', [
                        'name' => 'current_password_email', 'label' => 'Confirm with your password',
                        'type' => 'password', 'required' => true, 'autocomplete' => 'current-password',
                    ]) ?>
                    <button class="sl-btn sl-btn-outline-light" type="submit">Request email change</button>
                </form>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-2">Password</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Changing your password signs you out on every other device.
                </p>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="password_change">
                    <?= component('field', ['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
                    <?= component('field', ['name' => 'new_password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'help' => 'At least 10 characters.', 'attrs' => ['minlength' => '10']]) ?>
                    <?= component('field', ['name' => 'new_password_confirm', 'label' => 'Confirm new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
                    <button class="sl-btn sl-btn-outline-light" type="submit">Change password</button>
                </form>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Account</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Status', 'value' => 'active', 'type' => 'badge'],
                    ['label' => 'Member since', 'value' => '2026-03-14 19:10:00', 'type' => 'datetime'],
                    ['label' => 'Orders placed', 'value' => '14'],
                    ['label' => 'Email verified', 'value' => 'Yes'],
                ]]) ?>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Closing your account</h2>
                <p class="t-caption t-muted tw-mb-4">
                    We minimise your personal data when you close an account. Order and payment
                    records are kept, because a receipt you were given and a seller's sales history
                    are financial facts rather than profile fields.
                </p>
                <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="account_close">
                    <button class="sl-btn sl-btn-danger sl-btn-sm sl-btn-block" type="submit">
                        Request account closure
                    </button>
                </form>
            </div>
        </aside>
    </div>
</div>
