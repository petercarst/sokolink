<?php
declare(strict_types=1);
/**
 * Create a customer account.
 *
 * The marketing consent box is separate from the terms box and starts
 * UNTICKED. Consent has to be an action the person takes, not a default they
 * have to notice and undo (FR-CRM-05, FR-CRM-06).
 */
?>
<h1 class="t-display-md tw-mb-2">Create an account</h1>
<p class="t-body-md t-muted tw-mb-8">
    You need an account to place an order, track it and reorder later.
</p>

<form method="post" action="<?= e(route('auth.register.submit')) ?>" data-validate>
    <?= csrf_field() ?>

    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
        <?= component('field', [
            'name' => 'first_name', 'label' => 'First name', 'required' => true,
            'autocomplete' => 'given-name',
        ]) ?>
        <?= component('field', [
            'name' => 'last_name', 'label' => 'Last name', 'required' => true,
            'autocomplete' => 'family-name',
        ]) ?>
    </div>

    <?= component('field', [
        'name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true,
        'autocomplete' => 'email', 'placeholder' => 'you@example.co.tz',
        'help' => 'Order updates, receipts and collection codes are sent here.',
    ]) ?>

    <?= component('field', [
        'name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true,
        'autocomplete' => 'tel', 'placeholder' => '+255 7XX XXX XXX',
        'help' => 'Used by sellers and delivery agents to reach you about an order.',
    ]) ?>

    <div class="sl-field">
        <label class="sl-label" for="reg-password">
            Password<span class="sl-required" aria-hidden="true">*</span>
            <span class="visually-hidden"> (required)</span>
        </label>
        <div class="tw-flex tw-gap-2">
            <input class="sl-input" type="password" id="reg-password" name="password"
                   required minlength="10" autocomplete="new-password"
                   aria-describedby="reg-password-help">
            <button class="sl-btn sl-btn-outline-light sl-btn-icon" type="button"
                    data-password-toggle="reg-password" aria-pressed="false" aria-label="Show password">
                <?= component('icon', ['name' => 'eye', 'size' => 18]) ?>
            </button>
        </div>
        <span class="sl-help" id="reg-password-help">
            At least 10 characters. A short phrase you will remember beats a short password with
            symbols in it.
        </span>
    </div>

    <label class="sl-check">
        <input type="checkbox" name="accept_terms" required>
        <span class="sl-check-label">
            I accept the <a href="<?= e(route('page.terms')) ?>">terms of service</a>
            and the <a href="<?= e(route('page.privacy')) ?>">privacy notice</a>.
            <span class="sl-required" aria-hidden="true">*</span>
        </span>
    </label>

    <label class="sl-check tw-mb-6">
        <input type="checkbox" name="marketing_consent">
        <span class="sl-check-label">
            Send me reorder reminders and occasional offers.
            <span class="t-micro t-muted tw-block tw-mt-1">
                Optional. Order and delivery updates are sent either way, because they are part of
                the service you are buying. You can change this whenever you like.
            </span>
        </span>
    </label>

    <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">
        Create account
    </button>
</form>

<p class="t-caption t-muted tw-text-center tw-mb-0">
    Already have an account? <a href="<?= e(route('auth.login')) ?>">Log in</a>
</p>
