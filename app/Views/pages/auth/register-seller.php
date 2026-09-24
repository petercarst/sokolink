<?php
declare(strict_types=1);
/**
 * Apply to sell.
 *
 * This form creates an APPLICATION, not a live store. The page says so
 * plainly, because a seller who thinks they are trading and is not will
 * discover it the expensive way (FR-AUTH-06, USER_FLOWS.md Flow B).
 */
?>
<h1 class="t-display-md tw-mb-2">Apply to sell</h1>
<p class="t-body-md t-muted tw-mb-6">
    Tell us about your business and your first store. An administrator reviews every application.
</p>

<div class="sl-alert sl-alert-info tw-mb-8">
    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
    <span>
        <strong>This creates an application, not a shop.</strong>
        You can log in straight away and finish your store profile, but you cannot list products or
        receive orders until an administrator approves you. If we turn the application down, you get
        a reason and can reapply.
    </span>
</div>

<form method="post" action="<?= e(route('auth.register.seller.submit')) ?>" data-validate>
    <?= csrf_field() ?>

    <h2 class="t-heading-md tw-mb-4">About the business</h2>

    <?= component('field', [
        'name' => 'business_name', 'label' => 'Business or trading name', 'required' => true,
        'help' => 'This is what customers see as the seller name.',
    ]) ?>

    <?= component('field', [
        'name' => 'business_type', 'label' => 'Type of business', 'type' => 'select', 'required' => true,
        'options' => [
            ''             => 'Choose one',
            'sole_trader'  => 'Sole trader',
            'partnership'  => 'Partnership',
            'company'      => 'Registered company',
            'cooperative'  => 'Cooperative',
        ],
    ]) ?>

    <?= component('field', [
        'name' => 'registration_number', 'label' => 'Business registration number',
        'help' => 'Optional at application. Required before your first payout is set up.',
    ]) ?>

    <h2 class="t-heading-md tw-mt-8 tw-mb-4">Contact</h2>

    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
        <?= component('field', [
            'name' => 'contact_name', 'label' => 'Your name', 'required' => true,
            'autocomplete' => 'name',
        ]) ?>
        <?= component('field', [
            'name' => 'contact_phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true,
            'autocomplete' => 'tel', 'placeholder' => '+255 7XX XXX XXX',
        ]) ?>
    </div>

    <?= component('field', [
        'name' => 'contact_email', 'label' => 'Email address', 'type' => 'email', 'required' => true,
        'autocomplete' => 'email',
        'help' => 'We send the approval decision here.',
    ]) ?>

    <div class="sl-field">
        <label class="sl-label" for="seller-password">
            Choose a password<span class="sl-required" aria-hidden="true">*</span>
            <span class="visually-hidden"> (required)</span>
        </label>
        <div class="tw-flex tw-gap-2">
            <input class="sl-input" type="password" id="seller-password" name="password"
                   required minlength="10" autocomplete="new-password">
            <button class="sl-btn sl-btn-outline-light sl-btn-icon" type="button"
                    data-password-toggle="seller-password" aria-pressed="false" aria-label="Show password">
                <?= component('icon', ['name' => 'eye', 'size' => 18]) ?>
            </button>
        </div>
        <span class="sl-help">At least 10 characters.</span>
    </div>

    <h2 class="t-heading-md tw-mt-8 tw-mb-4">Your first store</h2>

    <?= component('field', [
        'name' => 'store_name', 'label' => 'Store name', 'required' => true,
        'help' => 'For example: Mama Lishe - Kariakoo. Customers use this to choose a collection point.',
    ]) ?>

    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
        <?= component('field', ['name' => 'store_region', 'label' => 'Region', 'required' => true]) ?>
        <?= component('field', ['name' => 'store_district', 'label' => 'District', 'required' => true]) ?>
    </div>

    <?= component('field', ['name' => 'store_street', 'label' => 'Street and building', 'required' => true]) ?>

    <?= component('field', [
        'name' => 'store_landmark', 'label' => 'Nearest landmark',
        'help' => 'Helps customers and delivery agents find you.',
    ]) ?>

    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
        <legend class="sl-label">What can you offer?<span class="sl-required" aria-hidden="true">*</span></legend>
        <label class="sl-check">
            <input type="checkbox" name="offers[]" value="pickup" checked>
            <span class="sl-check-label">Click and collect from this store</span>
        </label>
        <label class="sl-check">
            <input type="checkbox" name="offers[]" value="delivery">
            <span class="sl-check-label">
                Home delivery
                <span class="t-micro t-muted tw-block">Delivery is carried out by platform agents in served zones.</span>
            </span>
        </label>
    </fieldset>

    <?= component('field', [
        'name' => 'categories', 'label' => 'What do you sell?', 'type' => 'textarea', 'required' => true,
        'help' => 'A sentence or two. For example: dry goods, cooking oil, rice and household cleaning products.',
    ]) ?>

    <label class="sl-check tw-mb-6">
        <input type="checkbox" name="accept_terms" required>
        <span class="sl-check-label">
            I accept the <a href="<?= e(route('page.terms')) ?>">terms of service</a>
            and confirm the information above is accurate.
            <span class="sl-required" aria-hidden="true">*</span>
        </span>
    </label>

    <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-4" type="submit">
        Submit application
    </button>
</form>

<p class="t-caption t-muted tw-text-center tw-mb-0">
    Just want to buy? <a href="<?= e(route('auth.register')) ?>">Create a customer account</a>
</p>
